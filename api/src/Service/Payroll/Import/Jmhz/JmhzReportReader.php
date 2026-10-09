<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportFileException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;

/**
 * Čtení měsíčního hlášení JMHZ vytvořeného jiným mzdovým programem.
 *
 * Mapa elementů je zrcadlem {@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlSerializer}
 * — co aplikace do hlášení píše, to tu umí přečíst zpátky. Formulářové varianty
 * (`bezPriznaku`, `cinnostKS`, `pestoun`, …) sdílejí názvy bloků
 * z `formCommonTypes.xsd`, takže se čtou stejnými relativními cestami; blok,
 * který varianta nemá, zůstane `null`.
 *
 * Bezpečnost je stejná jako u registrací: DOCTYPE a entity se odmítají celé,
 * síť je vypnutá a obsah se ověřuje proti připnutému XSD 1.4.3.6 (včetně
 * otisků celého balíku, {@see JmhzSchemaCatalog}).
 *
 * ── Měkký režim ─────────────────────────────────────────────────────────────
 * Cizí programy posílají i hlášení podle starší nebo novější verze schématu.
 * Takový soubor se přijme S VAROVÁNÍM jen tehdy, když KAŽDOU chybu schématu
 * lze podle čísla řádku přiřadit k elementu, který leží uvnitř bloku, ze
 * kterého import nic nečte (ELDP, PVPOJ, souhrnná vrstva zaměstnavatele,
 * roční úhrny, náhrady…). Chyba v hlavičce, v identifikaci nebo v bloku, ze
 * kterého se přebírá (souhrnná data, pojištění, vykonávaná pozice, mzda),
 * soubor odmítne — přejmenovaný nebo přesunutý element by se jinak tiše
 * přečetl jako „neuvedeno" a do evidence by šla nula. Jednořádkové XML chyby
 * lokalizovat neumí, takže v měkkém režimu neprojde nikdy.
 */
final class JmhzReportReader
{
    /** Bloky, ze kterých import nečte; jen chyby schématu uvnitř nich smí měkký režim přejít. */
    private const UNREAD_BLOCKS = [
        JmhzSchemaCatalog::NS_PODANI => ['VENDOR', 'SENDER'],
        JmhzSchemaCatalog::NS_SOUHRN => ['souhrn'],
        JmhzSchemaCatalog::NS_PVPOJ => ['PVPOJ'],
        JmhzSchemaCatalog::NS_FORM => [
            'eldpSeznam',
            'trvani',
            'pojisteniZamestnanec',
            'pojisteniZamestnavatel',
            'slevaZamestnavatele',
            'vymerovaciZakladParagraf5',
            'docasnePrideleni',
            'neodpracovaneHodiny',
            'prekazkyVPraci',
            'rocniUhrny',
            'mzdaCista',
            'zdravPojZamestnavatel',
            'zdravPojZamestnanec',
            'nahrady',
            'specifickaSkutecnost',
            'prispevekZamestnavatele',
        ],
    ];

    /** Druhy nepřenositelných slev evidence podle elementu hlášení (10299–10302). */
    public const CREDIT_ELEMENTS = [
        'zakladniSleva' => 'taxpayer',
        'zakladniSlevaInvalidita12' => 'disability-basic',
        'rozsirenaSlevaInvalidita3' => 'disability-extended',
        'slevaZTPP' => 'ztp-p',
    ];

    public function __construct(
        private readonly JmhzSchemaCatalog $schemas = new JmhzSchemaCatalog(),
    ) {}

    /**
     * Rozhodne jen podle kořenového elementu, jestli soubor patří tomuhle
     * čtení. Nic nevaliduje — to udělá {@see read()}.
     */
    public static function isJmhz(string $content): bool
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $content) === 1) {
            return false;
        }
        $reader = new \XMLReader();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$reader->XML($content, null, LIBXML_NONET)) {
                return self::rootStartTagIsJmhz($content);
            }
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    return $reader->localName === 'jmhz'
                        && $reader->namespaceURI === JmhzSchemaCatalog::NS_PODANI;
                }
            }

            return self::rootStartTagIsJmhz($content);
        } catch (\Throwable) {
            return self::rootStartTagIsJmhz($content);
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Novější libxml2 (Linux) u useknutého dokumentu ohlásí chybu dřív, než
     * XMLReader vydá první prvek; starší (Windows) kořen vydá. Rozbitý soubor
     * s kořenem hlášení ale patří tomuhle čtení, aby ho {@see read()} odmítl
     * jako neplatné XML — proto se kořen pozná i podle úvodní značky: `jmhz`
     * (s prefixem i bez) s deklarovaným jmenným prostorem podání.
     */
    private static function rootStartTagIsJmhz(string $content): bool
    {
        if (preg_match(
            '/^\s*(?:<\?xml[^>]*\?>\s*)?(?:<!--.*?-->\s*)*<(?:([A-Za-z_][\w.\-]*):)?jmhz((?:\s[^>]*)?)\/?>/s',
            $content,
            $match,
        ) !== 1) {
            return false;
        }
        $declaration = ($match[1] ?? '') === '' ? 'xmlns' : 'xmlns:' . $match[1];

        return preg_match(
            '/\s' . preg_quote($declaration, '/') . '\s*=\s*(["\'])'
                . preg_quote(JmhzSchemaCatalog::NS_PODANI, '/') . '\1/',
            $match[2] ?? '',
        ) === 1;
    }

    /** @throws RegistrationImportFileException */
    public function read(string $content): JmhzReportFile
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $content) === 1) {
            throw new RegistrationImportFileException(
                'Soubor obsahuje definici DOCTYPE nebo ENTITY, kterou import z bezpečnostních '
                . 'důvodů nepřijímá. Nahrajte hlášení přesně tak, jak ho vytvořil mzdový program.',
            );
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            if (!$document->loadXML($content, LIBXML_NONET | LIBXML_NOCDATA)) {
                throw new RegistrationImportFileException(
                    'Soubor není platné XML' . $this->detail(libxml_get_errors())
                    . '. Zkontrolujte, že nahráváte měsíční hlášení JMHZ ve formátu XML.',
                );
            }
            if ($document->doctype !== null) {
                throw new RegistrationImportFileException(
                    'Soubor obsahuje definici DOCTYPE, kterou import z bezpečnostních důvodů nepřijímá.',
                );
            }
            $root = $document->documentElement;
            if ($root === null
                || $root->localName !== 'jmhz'
                || $root->namespaceURI !== JmhzSchemaCatalog::NS_PODANI
            ) {
                throw new RegistrationImportFileException('Soubor není měsíční hlášení JMHZ.');
            }
            $warnings = $this->validate($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $this->parse($document, $warnings);
    }

    /**
     * Formulář osoby z dokumentu, který složil {@see JmhzAttributeDocument} z atributů
     * jiného mzdového programu (PAMICA). Soubor tu není, takže se nic neověřuje proti
     * XSD; mapa elementů na vlastnosti formuláře je ale tatáž jako u nahraného hlášení.
     *
     * @throws RegistrationImportFileException formulář nemá platné GUID, typ nebo hodnotu
     */
    public function formFromDocument(DOMDocument $document, int $position): JmhzReportForm
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('p', JmhzSchemaCatalog::NS_PODANI);
        $xpath->registerNamespace('f', JmhzSchemaCatalog::NS_FORM);
        $root = $document->documentElement
            ?? throw new RegistrationImportFileException('Hlášení je prázdné.');
        $node = $this->element($xpath, 'p:formulareOsob/p:formularOsoby', $root)
            ?? throw new RegistrationImportFileException('Hlášení nemá formulář osoby.');

        return $this->form($xpath, $node, $position);
    }

    /**
     * @return list<string> varování měkkého režimu (prázdné = soubor prošel XSD)
     */
    private function validate(DOMDocument $document): array
    {
        try {
            $schema = $this->schemas->entryPoint();
        } catch (JmhzXmlException $e) {
            throw new RegistrationImportFileException($e->getMessage(), 0, $e);
        }
        libxml_clear_errors();
        if ($document->schemaValidate($schema['path'])) {
            return [];
        }
        $errors = libxml_get_errors();
        libxml_clear_errors();
        if (!$this->onlyUnreadBlocks($document, $errors)) {
            throw new RegistrationImportFileException(
                'Soubor neodpovídá schématu měsíčního hlášení JMHZ ' . JmhzSchemaCatalog::PACKAGE_KEY
                . $this->detail($errors) . '. Chyba leží v části, ze které import údaje přebírá, '
                . 'takže se soubor nepřevezme ani zčásti.',
            );
        }

        return [
            'Soubor neodpovídá připnutému schématu JMHZ ' . JmhzSchemaCatalog::PACKAGE_KEY
            . ' (nejspíš jde o jinou verzi datové věty)' . $this->detail($errors)
            . '. Všechny odchylky leží v částech, ze kterých import nic nepřebírá '
            . '(ELDP, přehled pojistného, souhrnná vrstva), proto se soubor převzal s varováním.',
        ];
    }

    /** @param list<\LibXMLError> $errors */
    private function onlyUnreadBlocks(DOMDocument $document, array $errors): bool
    {
        if ($errors === []) {
            return false;
        }
        $byLine = [];
        $walker = static function (DOMNode $node) use (&$walker, &$byLine): void {
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $byLine[$child->getLineNo()][] = $child;
                    $walker($child);
                }
            }
        };
        $walker($document);
        foreach ($errors as $error) {
            $elements = $byLine[$error->line] ?? [];
            if ($error->line <= 0 || $elements === []) {
                return false;
            }
            foreach ($elements as $element) {
                if (!$this->insideUnreadBlock($element)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function insideUnreadBlock(DOMElement $element): bool
    {
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            $names = self::UNREAD_BLOCKS[(string) $node->namespaceURI] ?? [];
            if (in_array($node->localName, $names, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $warnings */
    private function parse(DOMDocument $document, array $warnings): JmhzReportFile
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('p', JmhzSchemaCatalog::NS_PODANI);
        $xpath->registerNamespace('f', JmhzSchemaCatalog::NS_FORM);
        $root = $document->documentElement
            ?? throw new RegistrationImportFileException('Soubor je prázdný.');

        $guid = $this->guid($this->text($xpath, 'p:hlavicka/p:idPodani', $root), 'idPodani');
        $type = $this->text($xpath, 'p:hlavicka/p:typPodani', $root);
        if (!in_array($type, ['R', 'O', 'S'], true)) {
            throw new RegistrationImportFileException('Hlášení nemá platný typ podání (R, O nebo S).');
        }
        $month = $this->int($this->text($xpath, 'p:hlavicka/p:mesic', $root), 'mesic');
        $year = $this->int($this->text($xpath, 'p:hlavicka/p:rok', $root), 'rok');
        if ($month === null || $year === null || !checkdate($month, 1, $year)) {
            throw new RegistrationImportFileException('Hlášení nemá platný měsíc a rok.');
        }
        $filledAt = $this->text($xpath, 'p:hlavicka/p:datumVyplneni', $root)
            ?? throw new RegistrationImportFileException('Hlášení nemá datum vyplnění.');
        $vendor = null;
        $vendorNode = $this->element($xpath, 'p:VENDOR', $root);
        if ($vendorNode !== null) {
            $vendor = trim($vendorNode->getAttribute('productName') . ' ' . $vendorNode->getAttribute('productVersion'));
            $vendor = $vendor === '' ? null : $vendor;
        }

        $forms = [];
        $position = 0;
        foreach ($xpath->query('p:formulareOsob/p:formularOsoby', $root) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $forms[] = $this->form($xpath, $node, ++$position);
            }
        }

        return new JmhzReportFile(
            submissionGuid: $guid,
            submissionType: $type,
            year: $year,
            month: $month,
            filledAt: $filledAt,
            packageOrdinal: $this->int($this->text($xpath, 'p:hlavicka/p:balikPoradi', $root), 'balikPoradi'),
            packageCount: $this->int($this->text($xpath, 'p:hlavicka/p:balikyPocet', $root), 'balikyPocet'),
            vendor: $vendor,
            lenient: $warnings !== [],
            warnings: $warnings,
            forms: $forms,
            variableSymbol: $this->text($xpath, 'p:hlavicka/p:variabilniSymbol', $root),
        );
    }

    private function form(DOMXPath $xpath, DOMElement $node, int $position): JmhzReportForm
    {
        $guid = $this->guid($this->text($xpath, 'p:hlavicka/p:idFormulare', $node), 'idFormulare');
        $type = $this->text($xpath, 'p:hlavicka/p:typFormulare', $node);
        if (!in_array($type, ['R', 'O', 'S'], true)) {
            throw new RegistrationImportFileException("Formulář {$guid} nemá platný typ (R, O nebo S).");
        }
        $primary = $this->bool($this->text($xpath, 'p:hlavicka/p:primarniPpv', $node), 'primarniPpv');
        $body = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement
                && $child->namespaceURI === JmhzSchemaCatalog::NS_FORM
                && in_array($child->localName, JmhzReportForm::VARIANTS, true)
            ) {
                $body = $child;
                break;
            }
        }
        if ($body === null) {
            return new JmhzReportForm($position, $guid, $type, $primary, null);
        }

        $t = fn (string $path): ?string => $this->text($xpath, $path, $body);
        $i = fn (string $path): ?int => $this->int($t($path), $path);
        $b = fn (string $path): ?bool => $this->bool($t($path), $path);

        $summary = $this->element($xpath, 'f:souhrnDataZec', $body);
        $advance = null;
        if ($this->element($xpath, 'f:souhrnDataZec/f:zalohaNaDan', $body) !== null) {
            $advance = [
                'base' => $i('f:souhrnDataZec/f:zalohaNaDan/f:zakladDane'),
                'computed' => $i('f:souhrnDataZec/f:zalohaNaDan/f:vypoctenaZaloha'),
                'after_credits' => $i('f:souhrnDataZec/f:zalohaNaDan/f:danZalohaPoSleve'),
                'bonus' => $i('f:souhrnDataZec/f:zalohaNaDan/f:danBonus'),
            ];
        }
        $withholding = null;
        if ($this->element($xpath, 'f:souhrnDataZec/f:zvlastniSazbaDane', $body) !== null) {
            $withholding = [
                'base' => $i('f:souhrnDataZec/f:zvlastniSazbaDane/f:zakladDane'),
                'tax' => $i('f:souhrnDataZec/f:zvlastniSazbaDane/f:srazenaDan'),
            ];
        }
        $credits = [];
        foreach (self::CREDIT_ELEMENTS as $element => $kind) {
            $amount = $i('f:souhrnDataZec/f:prohlaseniPoplatnikaDane/f:' . $element);
            if ($amount !== null) {
                $credits[$kind] = $amount;
            }
        }

        $workplace = null;
        if ($this->element($xpath, 'f:vykonavanaPozice/f:mistoVykonuPrace', $body) !== null) {
            $workplace = [
                'city' => $t('f:vykonavanaPozice/f:mistoVykonuPrace/f:obec') ?? '',
                'municipality_code' => $t('f:vykonavanaPozice/f:mistoVykonuPrace/f:kodObce') ?? '',
                'country_code' => strtoupper($t('f:vykonavanaPozice/f:mistoVykonuPrace/f:kodStatu') ?? ''),
            ];
        }
        $fund = null;
        if ($this->element($xpath, 'f:vykonavanaPozice/f:fondPracovniDoby', $body) !== null) {
            $fund = [
                'standard' => $t('f:vykonavanaPozice/f:fondPracovniDoby/f:stanovenyFond') ?? '',
                'agreed' => $t('f:vykonavanaPozice/f:fondPracovniDoby/f:sjednanyFond') ?? '',
                'weekly' => $t('f:vykonavanaPozice/f:fondPracovniDoby/f:stanovenaTydenniDoba') ?? '',
            ];
        }

        return new JmhzReportForm(
            position: $position,
            formGuid: $guid,
            formType: $type,
            primary: $primary,
            variant: $body->localName,
            personIdentifier: $t('f:identifikace/f:ikMpsv'),
            employmentIdentifier: $t('f:identifikace/f:idPpv'),
            lastName: $t('f:identifikace/f:prijmeni'),
            firstName: $t('f:identifikace/f:jmeno'),
            birthDate: $this->date($t('f:identifikace/f:datumNarozeni'), 'datumNarozeni'),
            startDate: $this->date($t('f:identifikace/f:datumNastupu'), 'datumNastupu'),
            activityCode: $t('f:identifikace/f:druhCinnosti'),
            hasSummary: $summary !== null,
            incomeTotal: $i('f:souhrnDataZec/f:prijmy/f:zuctovanoCelkem'),
            advance: $advance,
            withholding: $withholding,
            declarationSigned: $b('f:souhrnDataZec/f:prohlaseniPoplatnika'),
            credits: $credits,
            childCredit: $this->childCredit($xpath, $body),
            socialBase: $i('f:pojisteni/f:vymerovaciZaklad/f:castkaOdvodPojistneho'),
            socialDiscount: $b('f:pojisteni/f:slevaZamestnance/f:slevaZamestnanceEvidovana'),
            orchardDiscount: $b('f:pojisteni/f:slevaZamestnance/f:slevaZamestnanceOvoZelEvidovana'),
            hasPosition: $this->element($xpath, 'f:vykonavanaPozice', $body) !== null,
            workplace: $workplace,
            apz: $b('f:vykonavanaPozice/f:uplatnujiPrispevekApz'),
            apzInstrument: $t('f:vykonavanaPozice/f:nastrojApzKod'),
            functionalBenefits: $b('f:vykonavanaPozice/f:funkcniPozitky'),
            temporaryAssignment: $b('f:vykonavanaPozice/f:docasnePrideleniEvidovano'),
            fund: $fund,
            evidenceDays: $i('f:prubehZamestnani/f:odpracovaneDny/f:dnyEvidencniStav'),
            workedDays: $i('f:prubehZamestnani/f:odpracovaneDny/f:dnyOdpracovanePocet'),
            workedMillihours: $this->scaled($t('f:prubehZamestnani/f:odpracovaneHodiny/f:pocet'), 3, 'pocet'),
            taxableIncome: $i('f:prijem/f:dan/f:zakladDane'),
            wage: $i('f:mzda/f:mzdaZuctovana'),
            irregularBonuses: $i('f:mzda/f:mzdaRozpad/f:odmenyNepravidelne'),
            standbyPay: $i('f:mzda/f:odmeny/f:pohotovost'),
            averageHourlyMilli: $this->scaled($t('f:mzda/f:vydelek/f:vydelekPrumernyHod'), 3, 'vydelekPrumernyHod'),
            insuranceFrom: $this->lenientDate($t('f:pojisteni/f:trvani/f:pojisteniOd')),
            insuranceTo: $this->lenientDate($t('f:pojisteni/f:trvani/f:pojisteniDo')),
            eldp: $this->eldp($xpath, $body),
            employeeSocial: $this->lenientInt($t('f:pojisteni/f:pojisteniZamestnanec/f:socialniPojisteni')),
            employerSocial: $this->lenientInt($t('f:pojisteni/f:pojisteniZamestnavatel/f:socialniPojisteni')),
            employeeHealth: $this->lenientInt($t('f:souhrnDataZec/f:zdravPojZamestnanec/f:zdravotniPojisteni')),
            employerHealth: $this->lenientInt($t('f:souhrnDataZec/f:zdravPojZamestnavatel/f:zdravotniPojisteni')),
            netWage: $this->lenientInt($t('f:souhrnDataZec/f:mzdaCista/f:mzdaCista')),
            deductionsRecorded: $this->lenientBool($t('f:souhrnDataZec/f:mzdaCista/f:srazkyZeMzdyEvidovany')),
            tariff: $i('f:mzda/f:mzdaRozpad/f:tarif'),
            unworkedMillihours: $this->lenientScaled($t('f:prubehZamestnani/f:neodpracovaneHodiny/f:hodinyNeodpracCelkem'), 3),
            leaveMillihours: $this->lenientScaled($t('f:prubehZamestnani/f:neodpracovaneHodiny/f:hodinyNeodpracDovol'), 3),
            absenceMillihours: $this->absenceMillihours($xpath, $body),
            uninsuredIncome: $i('f:pojisteni/f:vymerovaciZaklad/f:prijemNepojistenaCinnost'),
            employerDiscount: $this->lenientBool($t('f:pojisteni/f:slevaZamestnavatele/f:slevaZamestnavateleEvidovana')),
            employerDiscountReasons: $this->texts($xpath, 'f:pojisteni/f:slevaZamestnavatele/f:slevaZamestnavateleRozpad/f:duvodUplatneni', $body),
            employerContributions: $this->employerContributions($xpath, $body),
        );
    }

    /**
     * Příspěvky zaměstnavatele z osvobozených příjmů souhrnných dat (`prispevekZamestnavatele`).
     * Blok smí měkký režim přejít, proto se čte tolerantně; chybějící položka je nula.
     *
     * @return array{pension_supplementary:int,supplementary_savings:int,pension_insurance:int,life_insurance:int,dip:int,long_term_care:int}|null
     */
    private function employerContributions(DOMXPath $xpath, DOMElement $body): ?array
    {
        $block = $this->element($xpath, 'f:souhrnDataZec/f:prijmy/f:prispevekZamestnavatele', $body);
        if ($block === null) {
            return null;
        }
        $amount = fn (string $path): int => max(0, $this->lenientInt($this->text($xpath, $path, $block)) ?? 0);

        return [
            'pension_supplementary' => $amount('f:prispevekPenzPripoj'),
            'supplementary_savings' => $amount('f:prispevekDoplnPenzPripoj'),
            'pension_insurance' => $amount('f:prispevekPenzPoj'),
            'life_insurance' => $amount('f:prispevekZivotPoj'),
            'dip' => $amount('f:prispevekDip'),
            'long_term_care' => $amount('f:prispevekZelPojDlPece'),
        ];
    }

    /** @return list<string> neprázdné texty všech výskytů cesty */
    private function texts(DOMXPath $xpath, string $path, DOMNode $context): array
    {
        $nodes = $xpath->query($path, $context);
        $out = [];
        foreach ($nodes === false ? [] : $nodes as $node) {
            $value = trim((string) $node->textContent);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Seznam ELDP formuláře: kód první sekce s kódem a součty dnů přes sekce.
     * Blok `eldpSeznam` smí měkký režim přejít, proto se čte tolerantně.
     *
     * `sickness_excluded_days` jsou vyloučené dny § 18 odst. 7 (10366), `null`
     * když je hlášení nevydalo ({@see sicknessExcludedDays()}). `absence_days`
     * jsou dny nepřítomností, které se v příštím měsíci mohou táhnout dál
     * (nemoc 10358 a 10474, peněžitá pomoc v mateřství 10359, ošetřovné 10360,
     * výplata dávek 10475) — převzetí podle nich upozorní na rozběhnutou
     * neschopnost.
     *
     * @return array{code:?string,insurance_days:int,excluded_days:int,sickness_excluded_days:?int,absence_days:array<string,int>}|null
     */
    private function eldp(DOMXPath $xpath, DOMElement $body): ?array
    {
        $entries = $xpath->query('f:pojisteni/f:eldpSeznam/f:eldp', $body);
        if ($entries === false || $entries->length === 0) {
            return null;
        }
        $code = null;
        $days = 0;
        $excluded = 0;
        $sickness = 0;
        $sicknessKnown = false;
        $sicknessUnknown = false;
        $absence = array_fill_keys(self::ABSENCE_DAY_ELEMENTS, 0);
        foreach ($entries as $entry) {
            if (!$entry instanceof DOMElement) {
                continue;
            }
            $code ??= $this->text($xpath, 'f:kod', $entry);
            $days += $this->lenientInt($this->text($xpath, 'f:pocetDnu', $entry)) ?? 0;
            $excluded += $this->excludedDays($xpath, $entry);
            $section = $this->sicknessExcludedDays($xpath, $entry);
            if ($section === null) {
                $sicknessUnknown = $sicknessUnknown
                    || $this->element($xpath, 'f:vylouceneDny', $entry) !== null;
            } else {
                $sickness += $section;
                $sicknessKnown = true;
            }
            foreach (self::ABSENCE_DAY_ELEMENTS as $element) {
                $absence[$element] += $this->lenientInt(
                    $this->text($xpath, 'f:vylouceneDny/f:' . $element, $entry),
                ) ?? 0;
            }
        }

        return [
            'code' => $code,
            'insurance_days' => $days,
            'excluded_days' => $excluded,
            'sickness_excluded_days' => $sicknessKnown && !$sicknessUnknown ? $sickness : null,
            'absence_days' => $absence,
        ];
    }

    /** Podpoložky vyloučených dnů, které ukazují na nepřítomnost s dávkou nebo náhradou mzdy. */
    private const ABSENCE_DAY_ELEMENTS = [
        'docasNeschopnost', 'penezitaPomocMaterstvi', 'osetrovaniClenaRodiny',
        'pracovniNeschopnost', 'vyplaceniDavek',
    ];

    /**
     * Vyloučené dny § 18 odst. 7 zák. č. 187/2006 Sb. jedné sekce ELDP.
     *
     * Úhrn 10366, a když ho program nevyplnil, součet rozpadu 10473 + 10474 +
     * 10475 (datový slovník: 10366 = jejich součet). Úhrn vyloučených DOB
     * 10357 je jiná veličina (§ 16 odst. 4 zák. č. 155/1995 Sb.) a tady se
     * nepoužije: měsíc neplaceného volna má 10357 = 0 a 10366 = 31. `null`,
     * když sekce nenese ani úhrn, ani rozpad — nula by byla vymyšlený údaj.
     */
    private function sicknessExcludedDays(DOMXPath $xpath, DOMElement $entry): ?int
    {
        $total = $this->lenientInt($this->text($xpath, 'f:vylouceneDny/f:vyloucenePar18', $entry));
        if ($total !== null) {
            return $total;
        }
        $sum = null;
        foreach (self::EXCLUDED_REASONS as $element) {
            $days = $this->lenientInt($this->text($xpath, 'f:vylouceneDny/f:' . $element, $entry));
            if ($days !== null) {
                $sum = ($sum ?? 0) + $days;
            }
        }

        return $sum;
    }

    /** Podpoložky úhrnu vyloučených dob 10357 (§ 16 odst. 4 písm. a) a j) zákona č. 155/1995 Sb.). */
    private const EXCLUDED_BREAKDOWN = ['docasNeschopnost', 'penezitaPomocMaterstvi', 'osetrovaniClenaRodiny', 'otcovska', 'vyloucenePar16'];
    /** Vyloučené dny vykazované po důvodech (10473–10475); `vyloucenePar18` patří k nemocenskému, ne k ELDP. */
    private const EXCLUDED_REASONS = ['omluvenaNepritomnost', 'pracovniNeschopnost', 'vyplaceniDavek'];

    /**
     * Vyloučené dny jedné sekce ELDP: úhrn 10357, když ho program vyplnil. Cizí
     * programy ho ale často vynechávají a nesou jen podpoložky (typicky dny
     * výplaty dávek u mateřské) — pak je úhrnem větší ze součtů obou rozpisů,
     * které se vzájemně překrývají.
     */
    private function excludedDays(DOMXPath $xpath, DOMElement $entry): int
    {
        $total = $this->lenientInt($this->text($xpath, 'f:vylouceneDny/f:vylouceneDobyCelkem', $entry));
        if ($total !== null) {
            return $total;
        }
        $sum = function (array $elements) use ($xpath, $entry): int {
            $days = 0;
            foreach ($elements as $element) {
                $days += $this->lenientInt($this->text($xpath, 'f:vylouceneDny/f:' . $element, $entry)) ?? 0;
            }

            return $days;
        };

        return max($sum(self::EXCLUDED_BREAKDOWN), $sum(self::EXCLUDED_REASONS));
    }

    /**
     * Nemoc (s náhradou i bez ní) a OČR v součtu. Chybějící prvek je
     * nula jen tehdy, když blok neodpracovaných hodin existuje; bez bloku `null`.
     */
    private function absenceMillihours(DOMXPath $xpath, DOMElement $body): ?int
    {
        $block = $this->element($xpath, 'f:prubehZamestnani/f:neodpracovaneHodiny', $body);
        if ($block === null) {
            return null;
        }
        $sum = 0;
        foreach (['hodinyNeodpracNeschop', 'hodinyNeodpracOcr', 'hodinyNeodpracBezNahrady'] as $element) {
            $value = $this->text($xpath, 'f:' . $element, $block);
            if ($value === null) {
                continue;
            }
            $hours = $this->lenientScaled($value, 3);
            if ($hours === null) {
                return null;
            }
            $sum += $hours;
        }

        return $sum;
    }

    private function lenientInt(?string $value): ?int
    {
        return $value !== null && preg_match('/^\d{1,15}$/D', $value) === 1 ? (int) $value : null;
    }

    private function lenientBool(?string $value): ?bool
    {
        return match ($value) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }

    private function lenientScaled(?string $value, int $scale): ?int
    {
        try {
            return $this->scaled($value, $scale, 'hodiny');
        } catch (RegistrationImportFileException) {
            return null;
        }
    }

    /**
     * Začátek pojištění (10354) leží v bloku `trvani`, který měkký režim smí
     * přejít. Slouží jen jako náznak nástupu u exportu zaměstnanců, proto
     * nečitelná hodnota soubor neodmítne — prostě se nepoužije.
     */
    private function lenientDate(?string $value): ?string
    {
        try {
            return $this->date($value, 'pojisteniOd');
        } catch (RegistrationImportFileException) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    private function childCredit(DOMXPath $xpath, DOMElement $body): ?array
    {
        $block = $this->element($xpath, 'f:souhrnDataZec/f:prohlaseniPoplatnikaDane', $body);
        if ($block === null) {
            return null;
        }
        $monthly = $this->int($this->text($xpath, 'f:danoveZvyhodneniDetiMesic', $block), 'danoveZvyhodneniDetiMesic');
        $applied = $this->int($this->text($xpath, 'f:slevaDite', $block), 'slevaDite');
        $details = $this->element($xpath, 'f:zvyhodneniDetiMesic', $block);
        if ($monthly === null && $applied === null && $details === null) {
            return null;
        }
        $caregivers = [];
        $children = [];
        $other = null;
        if ($details !== null) {
            $other = $this->bool($this->text($xpath, 'f:vyzivujeJinaOsoba', $details), 'vyzivujeJinaOsoba');
            foreach ($xpath->query('f:jineOsoby/f:jinaOsoba', $details) ?: [] as $node) {
                if ($node instanceof DOMElement) {
                    $caregivers[] = $this->person($xpath, $node);
                }
            }
            foreach ($xpath->query('f:vyzivovaneDeti/f:vyzivovaneDite', $details) ?: [] as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }
                $child = $this->element($xpath, 'f:dite', $node)
                    ?? throw new RegistrationImportFileException('Vyživované dítě v hlášení nemá identitu.');
                $order = $this->text($xpath, 'f:poradi', $node);
                if (!in_array($order, ['1', '2', '3', 'N'], true)) {
                    throw new RegistrationImportFileException('Vyživované dítě v hlášení nemá platné pořadí (1, 2, 3 nebo N).');
                }
                $children[] = $this->person($xpath, $child) + [
                    'ztp_p' => $this->bool($this->text($xpath, 'f:prukazZtpp', $node), 'prukazZtpp') === true,
                    'order' => $order,
                ];
            }
        }

        return [
            'monthly' => $monthly,
            'applied' => $applied,
            'other_caregiver' => $other,
            'caregivers' => $caregivers,
            'children' => $children,
        ];
    }

    /** @return array{given_name:string,family_name:string,birth_date:?string,birth_number:?string} */
    private function person(DOMXPath $xpath, DOMElement $node): array
    {
        $given = $this->text($xpath, 'f:jmeno', $node);
        $family = $this->text($xpath, 'f:prijmeni', $node);
        if ($given === null || $family === null) {
            throw new RegistrationImportFileException('Osoba v prohlášení poplatníka nemá jméno a příjmení.');
        }

        return [
            'given_name' => $given,
            'family_name' => $family,
            'birth_date' => $this->date($this->text($xpath, 'f:datumNarozeni', $node), 'datumNarozeni'),
            'birth_number' => $this->text($xpath, 'f:rodneCislo', $node),
        ];
    }

    private function element(DOMXPath $xpath, string $path, DOMNode $context): ?DOMElement
    {
        $node = $xpath->query($path, $context)?->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function text(DOMXPath $xpath, string $path, DOMNode $context): ?string
    {
        $node = $this->element($xpath, $path, $context);
        if ($node === null) {
            return null;
        }
        $value = trim($node->textContent);

        return $value === '' ? null : $value;
    }

    private function guid(?string $value, string $label): string
    {
        if ($value === null
            || preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/D', $value) !== 1
        ) {
            throw new RegistrationImportFileException("Hlášení nemá platný GUID ({$label}).");
        }

        return strtoupper($value);
    }

    private function int(?string $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d{1,15}$/D', $value) !== 1) {
            throw new RegistrationImportFileException("Hodnota elementu {$label} „{$value}“ není nezáporné celé číslo.");
        }

        return (int) $value;
    }

    private function bool(?string $value, string $label): ?bool
    {
        return match ($value) {
            null => null,
            'true', '1' => true,
            'false', '0' => false,
            default => throw new RegistrationImportFileException("Hodnota elementu {$label} „{$value}“ není ano/ne."),
        };
    }

    /** Schéma pouští `xs:date` i s časovou zónou; evidence chce holé datum. */
    private function date(?string $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $match) !== 1
            || \DateTimeImmutable::createFromFormat('!Y-m-d', $match[1])?->format('Y-m-d') !== $match[1]
        ) {
            throw new RegistrationImportFileException("Hodnota elementu {$label} „{$value}“ není datum.");
        }

        return $match[1];
    }

    /** Desetinné číslo jako celé číslo v `10^-scale` jednotkách, bez plovoucí čárky. */
    private function scaled(?string $value, int $scale, string $label): ?int
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^(\d{1,12})(?:\.(\d{1,' . $scale . '}))?$/D', $value, $match) !== 1) {
            throw new RegistrationImportFileException("Hodnota elementu {$label} „{$value}“ není platné desetinné číslo.");
        }

        return (int) $match[1] * (10 ** $scale) + (int) str_pad($match[2] ?? '', $scale, '0');
    }

    /** @param list<\LibXMLError> $errors */
    private function detail(array $errors): string
    {
        $messages = [];
        foreach (array_slice($errors, 0, 3) as $error) {
            $messages[] = 'řádek ' . $error->line . ': ' . trim($error->message);
        }

        return $messages === [] ? '' : ' (' . implode('; ', $messages) . ')';
    }
}
