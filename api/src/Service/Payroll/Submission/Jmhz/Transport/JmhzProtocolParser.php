<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parser protokolů ČSSZ o zpracování měsíčního hlášení.
 *
 * Umí oba doložené druhy: protokol z dílčího podání (GovTalk obálka
 * s `ProcessingResult`) a protokol o kompletnosti (odpověď DZMH). Druh se
 * rozpozná z kořene, nehádá se z názvu souboru.
 *
 * Fail-closed všude, kde by tichý default znamenal, že se podání bude tvářit
 * jako přijaté: neznámý `result`, neznámý `Qualifier`, neznámý stav, neznámý
 * kód chyby, nečitelná chybová hláška i nečitelné XML jsou výjimky.
 */
final readonly class JmhzProtocolParser
{
    private const NS_DZMH = 'http://schemas.cssz.cz/JMHZ/dotazNaStav/2025';

    /**
     * Protokol o zpracování, který ČSSZ doručuje sama (typicky do datové
     * schránky). Není to odpověď na dotaz — chodí bez vyžádání a je to
     * doklad, který uživatel reálně dostane do ruky.
     */
    private const NS_PROCESSING = 'http://schemas.cssz.cz/JMHZ/ProtokolOZpracovani/2026';

    /** Doložené hodnoty `Qualifier` v odpovědi na poll u POX endpointu. */
    private const QUALIFIER_ACCEPTED = 'response';
    private const QUALIFIER_REJECTED = 'error';

    private const CLASSES = ['CSSZ_JMHZ', 'CSSZ_REGZEC'];
    private const ERROR_KINDS = ['prijem', 'zpracovani'];
    private const PART_SCOPES = [
        'global' => JmhzProtocolPartKind::General,
        'nezarazeno' => JmhzProtocolPartKind::General,
        'souhrn' => JmhzProtocolPartKind::Summary,
        'pvpoj' => JmhzProtocolPartKind::Insurance,
        'form' => JmhzProtocolPartKind::Form,
    ];

    /**
     * @param int $packageCount počet dílčích balíků hlášení; mění doložený
     *   výklad situace „všechny formuláře zamítnuty"
     */
    public function parse(
        string $xml,
        int $packageCount = 1,
        ?string $expectedCorrelation = null,
    ): JmhzProtocolReport
    {
        if ($packageCount < 1) {
            throw new JmhzTransportException(
                'jmhz_protocol_package_count_invalid',
                'Počet balíků hlášení musí být kladný.',
            );
        }
        $dom = $this->load($xml);
        $root = $dom->documentElement;
        if ($root === null) {
            throw new JmhzTransportException(
                'jmhz_protocol_unreadable',
                'Protokol ČSSZ neobsahuje kořenový element.',
            );
        }
        if ($root->localName === 'GovTalkMessage'
            && $root->namespaceURI === JmhzGovTalkEnvelope::NS_GOVTALK
        ) {
            return $this->parsePartialSubmission($dom, $packageCount);
        }
        if ($root->localName === 'DZMHOdpoved'
            && $root->namespaceURI === self::NS_DZMH
        ) {
            return $this->parseCompleteness($dom, $expectedCorrelation);
        }
        if ($root->localName === 'ProtokolOZpracovani'
            && $root->namespaceURI === self::NS_PROCESSING
        ) {
            return $this->parseProcessingProtocol($dom, $expectedCorrelation);
        }

        throw new JmhzTransportException(
            'jmhz_protocol_kind_unknown',
            'Kořen protokolu neodpovídá obálce GovTalk, odpovědi DZMH ani'
                . ' protokolu o zpracování.',
        );
    }

    private function parsePartialSubmission(
        DOMDocument $dom,
        int $packageCount,
    ): JmhzProtocolReport {
        $xpath = $this->xpath($dom);
        $class = $this->assertClass(
            $this->text($xpath, "//g:Header/g:MessageDetails/g:Class"),
        );
        $qualifier = trim(
            $this->text($xpath, "//g:Header/g:MessageDetails/g:Qualifier"),
        );
        if (!in_array(
            $qualifier,
            [self::QUALIFIER_ACCEPTED, self::QUALIFIER_REJECTED],
            true,
        )) {
            throw new JmhzTransportException(
                'jmhz_protocol_qualifier_unknown',
                "Hodnota `Qualifier` `{$qualifier}` v protokolu není doložená.",
            );
        }
        $correlation = trim(
            $this->text($xpath, "//g:Header/g:MessageDetails/g:CorrelationID"),
        );

        $result = $xpath->query("//*[local-name()='ProcessingResult']")->item(0);
        if (!$result instanceof DOMElement) {
            throw new JmhzTransportException(
                'jmhz_protocol_unreadable',
                'Protokol z dílčího podání neobsahuje element ProcessingResult.',
            );
        }
        $outcome = $this->assertOutcome($result->getAttribute('result'));
        $errors = $this->parseErrorMessage(
            $result->getAttribute('errMsg'),
            $result->getAttribute('errNumber'),
        );
        $parts = $this->parseItems($result);
        $status = $this->derivePartialStatus(
            $outcome,
            $parts,
            $packageCount,
            $this->intAttribute($result, 'countWar'),
        );
        // Obálka s příznakem zamítnutí smí doprovázet i částečné přijetí —
        // z pohledu odesílatele je to pořád odmítnutá zpráva, jen ne celá.
        // Rozpor je až tehdy, když obálka hlásí zamítnutí a uvnitř je čistý
        // průchod.
        if ($qualifier === self::QUALIFIER_REJECTED
            && in_array($status, [
                JmhzSubmissionStatus::ProcessedAndComplete,
                JmhzSubmissionStatus::ContainsPassableErrors,
            ], true)
        ) {
            throw new JmhzTransportException(
                'jmhz_protocol_qualifier_conflict',
                'Obálka protokolu hlásí zamítnutí, ale ProcessingResult vychází jinak.',
            );
        }

        $formStatuses = [];
        foreach ($parts as $part) {
            if ($part->kind === JmhzProtocolPartKind::Form
                && $part->formGuid !== null
            ) {
                $formStatuses[$part->formGuid] = $part->status;
            }
        }

        return new JmhzProtocolReport(
            JmhzProtocolKind::PartialSubmission,
            $class,
            $status,
            $this->correlationReference($correlation),
            $parts,
            $errors,
            $formStatuses,
        );
    }

    /** @return list<JmhzProtocolPart> */
    private function parseItems(DOMElement $result): array
    {
        $parts = [];
        foreach ($result->getElementsByTagName('Item') as $item) {
            $kind = JmhzProtocolPartKind::fromSubtype($item->getAttribute('subtype'));
            $outcome = $this->assertOutcome($item->getAttribute('result'));
            $formGuid = trim($item->getAttribute('sqnr'));
            $normalizedFormGuid = $kind === JmhzProtocolPartKind::Form
                ? $this->formGuid($formGuid)
                : null;
            if ($kind === JmhzProtocolPartKind::Form && $normalizedFormGuid === null) {
                throw new JmhzTransportException(
                    'jmhz_protocol_form_unidentified',
                    'Individualizovaná součást protokolu nemá GUID formuláře.',
                );
            }
            [$ikMpsv, $idPpv] = $this->identifier($item->getAttribute('identifier'));
            $parts[] = new JmhzProtocolPart(
                $kind,
                $outcome === 'OK'
                    ? JmhzSubmissionStatus::ProcessedAndComplete
                    : JmhzSubmissionStatus::Rejected,
                $normalizedFormGuid,
                $ikMpsv,
                $idPpv,
                $this->parseErrorMessage(
                    $item->getAttribute('errMsg'),
                    $item->getAttribute('errNum'),
                ),
            );
        }

        return $parts;
    }

    /**
     * Doložený výklad: obecná kontrola v prvním `Item` zamítá celé dílčí
     * podání; při jednom balíku zamítá i situace „všechny formuláře chybné",
     * při více balících je z ní částečné přijetí, protože stav hlášení
     * vyhodnocuje až cJMHZ. Bez `Item` prvků se z chybného protokolu nedá nic
     * změkčovat, takže zůstává zamítnutí.
     *
     * @param list<JmhzProtocolPart> $parts
     */
    private function derivePartialStatus(
        string $outcome,
        array $parts,
        int $packageCount,
        int $warnings,
    ): JmhzSubmissionStatus {
        $rejectedParts = array_values(array_filter(
            $parts,
            static fn (JmhzProtocolPart $part): bool
                => $part->status === JmhzSubmissionStatus::Rejected,
        ));
        if ($outcome === 'OK') {
            // Souhrnný výsledek „OK" a zamítnutá součást uvnitř si odporují.
            // Vzít souhrn a zamítnutí zahodit by přeneslo přijetí na podání,
            // které ČSSZ nepřijala celé.
            if ($rejectedParts !== []) {
                throw new JmhzTransportException(
                    'jmhz_protocol_outcome_conflict',
                    'Protokol hlásí souhrnný výsledek OK, ale některá jeho část'
                        . ' je zamítnutá.',
                );
            }

            return $warnings > 0
                ? JmhzSubmissionStatus::ContainsPassableErrors
                : JmhzSubmissionStatus::ProcessedAndComplete;
        }
        // Obecná vada zamítá celé dílčí podání bez ohledu na pořadí, ve kterém
        // ČSSZ položky vypsala. Hledat ji na prvním indexu znamenalo, že
        // přeházené pořadí zamítnutí tiše změkčilo na částečné přijetí.
        $general = array_values(array_filter(
            $rejectedParts,
            static fn (JmhzProtocolPart $part): bool
                => $part->kind === JmhzProtocolPartKind::General,
        ));
        if ($general !== []) {
            return JmhzSubmissionStatus::Rejected;
        }
        $forms = array_values(array_filter(
            $parts,
            static fn (JmhzProtocolPart $part): bool
                => $part->kind === JmhzProtocolPartKind::Form,
        ));
        if ($forms === []) {
            return JmhzSubmissionStatus::Rejected;
        }
        $accepted = array_filter(
            $forms,
            static fn (JmhzProtocolPart $part): bool
                => $part->status !== JmhzSubmissionStatus::Rejected,
        );
        if ($accepted === [] && $packageCount === 1) {
            return JmhzSubmissionStatus::Rejected;
        }

        return JmhzSubmissionStatus::PartiallyAccepted;
    }

    private function parseCompleteness(
        DOMDocument $dom,
        ?string $expectedCorrelation = null,
    ): JmhzProtocolReport
    {
        $xpath = $this->xpath($dom);
        $code = trim($this->text($xpath, '//d:stavMH/d:kod'));
        if (preg_match('/^[0-9]+$/D', $code) !== 1) {
            throw new JmhzTransportException(
                'jmhz_protocol_status_unknown',
                'Odpověď DZMH neobsahuje čitelný kód stavu hlášení.',
            );
        }
        $status = JmhzSubmissionStatus::fromCode((int) $code);
        $label = trim($this->text($xpath, '//d:stavMH/d:nazev'));
        if ($label !== '' && JmhzSubmissionStatus::fromDocumentedLabel($label) !== $status) {
            throw new JmhzTransportException(
                'jmhz_protocol_status_conflict',
                'Kód a název stavu hlášení v odpovědi DZMH si odporují.',
            );
        }

        // Odpověď DZMH nese protokoly VŠECH podání za období. Vybrat první
        // znamenalo přenést stav cizího podání na naše; vybírá se proto podle
        // očekávaného CorrelationID, a když ho volající nedodal, je jednoznačná
        // jen odpověď s jediným protokolem.
        $protocols = [];
        foreach ($xpath->query('//d:protokoly/d:protokol') as $protocol) {
            if ($protocol instanceof DOMElement) {
                $protocols[] = $protocol;
            }
        }
        $selected = $this->selectProtocol($xpath, $protocols, $expectedCorrelation);

        $correlation = null;
        $parts = [];
        $errors = [];
        $status = $selected === null ? $status : JmhzSubmissionStatus::fromCode(
            (int) trim($this->childText($xpath, $selected, 'kod')),
        );
        foreach ($protocols as $protocol) {
            if ($selected !== null && $protocol !== $selected) {
                continue;
            }
            $protocolStatus = JmhzSubmissionStatus::fromCode(
                (int) trim($this->childText($xpath, $protocol, 'kod')),
            );
            $reference = trim(
                $this->childText($xpath, $protocol, 'idKonkretnihoPodani'),
            );
            if ($correlation === null && $reference !== '') {
                $correlation = $this->correlationReference($reference);
            }
            foreach ($xpath->query('.//d:chybySeznam/d:chyba', $protocol) as $failure) {
                if (!$failure instanceof DOMElement) {
                    continue;
                }
                $error = JmhzProtocolError::fromCode(
                    (int) trim($this->childText($xpath, $failure, 'kod')),
                    trim($this->childText($xpath, $failure, 'popis')),
                );
                $this->assertErrorKind(
                    trim($this->childText($xpath, $failure, 'typChyby')),
                );
                $errors[] = $error;
                $parts[] = new JmhzProtocolPart(
                    $this->partScope(
                        trim($this->childText($xpath, $failure, 'castPodani')),
                    ),
                    $protocolStatus,
                    $this->formGuid(
                        trim($this->childText($xpath, $failure, 'idFormulare')),
                    ),
                    null,
                    null,
                    [$error],
                );
            }
        }

        // Odpověď DZMH dokládá, ke kterému formuláři chyba patří, ale ne stav
        // toho formuláře — per-součást stavy proto zůstávají prázdné a plní je
        // jen protokol z dílčího podání, kde je `Item/@result` doložený.
        return new JmhzProtocolReport(
            JmhzProtocolKind::Completeness,
            'CSSZ_JMHZ',
            $status,
            $correlation,
            $parts,
            $errors,
            [],
            null,
            $this->variableSymbol($xpath, 'd'),
            $this->periodPart($xpath, 'd', 'mesic', 1, 12),
            $this->periodPart($xpath, 'd', 'rok', 2000, 2100),
        );
    }

    /**
     * Protokol o zpracování, který ČSSZ doručí sama do datové schránky.
     *
     * Není to odpověď na dotaz a nechodí kanálem podání — přijde bez vyžádání
     * a je to doklad, který má uživatel v ruce. Parser ho proto musí umět
     * přečíst i ze souboru, ne jen z odpovědi na dotaz; ověřeno proti dvěma
     * skutečně doručeným protokolům (06/2026 a 07/2026).
     *
     * Nese `idPodani`, tedy GUID, který jsme sami vygenerovali. Dvojice
     * podání–protokol se proto dá spárovat naším identifikátorem, ne jen tím,
     * který přiděluje ČSSZ.
     */
    private function parseProcessingProtocol(
        DOMDocument $dom,
        ?string $expectedCorrelation = null,
    ): JmhzProtocolReport {
        $xpath = $this->xpath($dom);
        $code = trim($this->text($xpath, '//p:stavMH/p:kod'));
        if (preg_match('/^[0-9]+$/D', $code) !== 1) {
            throw new JmhzTransportException(
                'jmhz_protocol_status_unknown',
                'Protokol o zpracování neobsahuje čitelný kód stavu hlášení.',
            );
        }
        $status = JmhzSubmissionStatus::fromCode((int) $code);
        $label = trim($this->text($xpath, '//p:stavMH/p:nazev'));
        if ($label !== '' && JmhzSubmissionStatus::fromDocumentedLabel($label) !== $status) {
            throw new JmhzTransportException(
                'jmhz_protocol_status_conflict',
                'Kód a název stavu v protokolu o zpracování si odporují.',
            );
        }

        $reference = trim($this->text($xpath, '//p:idKonkretnihoPodani'));
        $correlation = $reference === '' ? null : $this->correlationReference($reference);
        // Protokol k cizímu podání se nesmí přiřadit k našemu jen proto, že
        // dorazil do téže schránky.
        if ($expectedCorrelation !== null
            && $correlation !== null
            && strcasecmp($correlation, $expectedCorrelation) !== 0
        ) {
            throw new JmhzTransportException(
                'jmhz_protocol_correlation_mismatch',
                'Protokol o zpracování patří jinému podání.',
            );
        }

        $errors = [];
        $parts = [];
        foreach ($xpath->query('//p:chybySeznam/p:chyba') as $failure) {
            if (!$failure instanceof DOMElement) {
                continue;
            }
            $code = trim($this->childText($xpath, $failure, 'kod', 'p'));
            if (preg_match('/^[0-9]+$/D', $code) !== 1) {
                throw new JmhzTransportException(
                    'jmhz_protocol_error_message_unreadable',
                    'Chyba v protokolu o zpracování nemá čitelný kód.',
                );
            }
            $error = JmhzProtocolError::fromCode(
                (int) $code,
                trim($this->childText($xpath, $failure, 'popis', 'p')),
            );
            $this->assertErrorKind(trim($this->childText($xpath, $failure, 'typChyby', 'p')));
            $errors[] = $error;
            $parts[] = new JmhzProtocolPart(
                $this->partScope(trim($this->childText($xpath, $failure, 'castPodani', 'p'))),
                $status,
                $this->formGuid(trim($this->childText($xpath, $failure, 'idFormulare', 'p'))),
                null,
                null,
                [$error],
            );
        }

        $submissionGuid = trim($this->text($xpath, '//p:idPodani'));

        return new JmhzProtocolReport(
            JmhzProtocolKind::Completeness,
            'CSSZ_JMHZ',
            $status,
            $correlation,
            $parts,
            $errors,
            [],
            $submissionGuid === '' ? null : $submissionGuid,
            $this->variableSymbol($xpath, 'p'),
            $this->periodPart($xpath, 'p', 'mesic', 1, 12),
            $this->periodPart($xpath, 'p', 'rok', 2000, 2100),
            $this->timestamp($xpath, '//p:datumProtokolu'),
            $this->timestamp($xpath, '//p:datumPodani'),
        );
    }

    /**
     * Vybere z odpovědi DZMH protokol, který patří očekávanému podání.
     *
     * @param list<DOMElement> $protocols
     */
    private function selectProtocol(
        \DOMXPath $xpath,
        array $protocols,
        ?string $expectedCorrelation,
    ): ?DOMElement {
        if ($protocols === []) {
            return null;
        }
        if ($expectedCorrelation === null) {
            if (count($protocols) > 1) {
                throw new JmhzTransportException(
                    'jmhz_protocol_ambiguous',
                    'Odpověď DZMH nese protokoly více podání; bez očekávaného'
                        . ' CorrelationID nelze určit, který z nich patří tomuhle podání.',
                );
            }

            return $protocols[0];
        }
        foreach ($protocols as $protocol) {
            $reference = trim($this->childText($xpath, $protocol, 'idKonkretnihoPodani'));
            if ($reference !== '' && hash_equals($expectedCorrelation, $reference)) {
                return $protocol;
            }
        }

        throw new JmhzTransportException(
            'jmhz_protocol_correlation_mismatch',
            'Odpověď DZMH neobsahuje protokol očekávaného podání.',
        );
    }

    /** @return list<JmhzProtocolError> */
    private function parseErrorMessage(string $message, string $firstCode): array
    {
        $normalized = trim($message);
        if ($normalized === '') {
            return [];
        }
        $normalized = preg_replace('/^[A-Za-z0-9_]+:\s*/', '', $normalized) ?? $normalized;
        $segments = preg_split('/;\s*(?=[0-9]+\s*-\s*)/u', $normalized) ?: [];
        $errors = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            if (preg_match('/^([0-9]+)\s*-\s*(.*)$/us', $segment, $matches) !== 1) {
                throw new JmhzTransportException(
                    'jmhz_protocol_error_message_unreadable',
                    'Chybovou hlášku protokolu nelze rozebrat na kód a text.',
                );
            }
            $errors[] = JmhzProtocolError::fromCode(
                (int) $matches[1],
                rtrim(trim($matches[2]), ';'),
            );
        }
        if ($errors === []) {
            throw new JmhzTransportException(
                'jmhz_protocol_error_message_unreadable',
                'Chybovou hlášku protokolu nelze rozebrat na kód a text.',
            );
        }
        $declared = trim($firstCode);
        if ($declared !== ''
            && $declared !== '0'
            && (int) $declared !== $errors[0]->code
        ) {
            throw new JmhzTransportException(
                'jmhz_protocol_error_code_conflict',
                'Deklarovaný kód první chyby neodpovídá chybové hlášce.',
            );
        }

        return $errors;
    }

    /** @return array{0:?string,1:?string} */
    private function identifier(string $identifier): array
    {
        $trimmed = trim($identifier);
        if ($trimmed === '' || $trimmed === ';') {
            return [null, null];
        }
        $pieces = explode(';', $trimmed);
        if (count($pieces) !== 2) {
            throw new JmhzTransportException(
                'jmhz_protocol_identifier_unreadable',
                'Atribut `identifier` protokolu nemá doložený tvar `IKMPSV;IDPPV`.',
            );
        }

        return [
            trim($pieces[0]) === '' ? null : trim($pieces[0]),
            trim($pieces[1]) === '' ? null : trim($pieces[1]),
        ];
    }

    private function assertOutcome(string $outcome): string
    {
        $normalized = strtoupper(trim($outcome));
        if ($normalized !== 'OK' && $normalized !== 'ERROR') {
            throw new JmhzTransportException(
                'jmhz_protocol_result_unknown',
                "Výsledek kontroly `{$outcome}` v protokolu není doložený.",
            );
        }

        return $normalized;
    }

    private function assertClass(string $class): string
    {
        $normalized = trim($class);
        if (!in_array($normalized, self::CLASSES, true)) {
            throw new JmhzTransportException(
                'jmhz_protocol_class_unknown',
                'Druh podání v protokolu není mezi doloženými hodnotami `Class`.',
            );
        }

        return $normalized;
    }

    private function assertErrorKind(string $kind): void
    {
        if ($kind !== '' && !in_array($kind, self::ERROR_KINDS, true)) {
            throw new JmhzTransportException(
                'jmhz_protocol_error_kind_unknown',
                "Typ chyby `{$kind}` v odpovědi DZMH není doložený.",
            );
        }
    }

    private function partScope(string $scope): JmhzProtocolPartKind
    {
        $kind = self::PART_SCOPES[$scope] ?? null;
        if ($kind === null) {
            throw new JmhzTransportException(
                'jmhz_protocol_part_unknown',
                "Část podání `{$scope}` v odpovědi DZMH není doložená.",
            );
        }

        return $kind;
    }

    /**
     * Platforma podání drží correlation reference ve svém tvaru; protokol, ze
     * kterého by vyšla hodnota mimo něj, se nesmí tvářit jako spárovatelný.
     */
    private function correlationReference(string $reference): ?string
    {
        if ($reference === '') {
            return null;
        }
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $reference) !== 1) {
            throw new JmhzTransportException(
                'jmhz_protocol_correlation_invalid',
                'CorrelationID v protokolu není v přípustném tvaru.',
            );
        }

        return $reference;
    }

    private function formGuid(string $guid): ?string
    {
        if ($guid === '') {
            return null;
        }
        if (preg_match(
            '/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/D',
            $guid,
        ) !== 1) {
            throw new JmhzTransportException(
                'jmhz_protocol_form_unidentified',
                'GUID formuláře v protokolu nemá tvar GUID.',
            );
        }

        return strtoupper($guid);
    }

    private function load(string $xml): DOMDocument
    {
        if (trim($xml) === '') {
            throw new JmhzTransportException(
                'jmhz_protocol_unreadable',
                'Protokol ČSSZ je prázdný.',
            );
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new JmhzTransportException(
                'jmhz_protocol_unreadable',
                'Protokol ČSSZ není platné XML.',
            );
        }

        return $dom;
    }

    private function xpath(DOMDocument $dom): DOMXPath
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('g', JmhzGovTalkEnvelope::NS_GOVTALK);
        $xpath->registerNamespace('d', self::NS_DZMH);
        $xpath->registerNamespace('p', self::NS_PROCESSING);

        return $xpath;
    }

    private function text(DOMXPath $xpath, string $expression): string
    {
        $node = $xpath->query($expression)->item(0);

        return $node === null ? '' : $node->textContent;
    }

    /**
     * Podřízený element v namespace, ve kterém protokol skutečně je.
     *
     * Prefix je parametr, ne konstanta: odpověď DZMH a protokol o zpracování
     * mají stejné názvy elementů v RŮZNÝCH namespace. Napevno zadrátovaný `d:`
     * u protokolu o zpracování znamenal, že se `kod` chyby nenašel, přečetl se
     * jako nula a `JmhzProtocolError::fromCode(0)` shodila celý protokol —
     * tedy právě ten, který má chyby a kvůli kterému se do něj kouká.
     */
    private function childText(
        DOMXPath $xpath,
        DOMElement $context,
        string $localName,
        string $prefix = 'd',
    ): string {
        $node = $xpath->query("./{$prefix}:{$localName}", $context)->item(0);

        return $node === null ? '' : $node->textContent;
    }

    /**
     * Variabilní symbol z protokolu. Prázdný je přípustný (nese ho jen část
     * druhů), ale nečitelný ne — s VS mimo doložený tvar by se nedalo ověřit,
     * komu protokol patří, a tvářit se přitom, že ověřen byl.
     */
    private function variableSymbol(DOMXPath $xpath, string $prefix): ?string
    {
        $value = trim($this->text($xpath, "//{$prefix}:variabilniSymbol"));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[0-9]{1,10}$/D', $value) !== 1) {
            throw new JmhzTransportException(
                'jmhz_protocol_variable_symbol_invalid',
                'Variabilní symbol v protokolu nemá tvar nejvýše deseti číslic.',
            );
        }

        return $value;
    }

    private function periodPart(
        DOMXPath $xpath,
        string $prefix,
        string $element,
        int $min,
        int $max,
    ): ?int {
        $value = trim($this->text($xpath, "//{$prefix}:{$element}"));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[0-9]{1,4}$/D', $value) !== 1
            || (int) $value < $min
            || (int) $value > $max
        ) {
            throw new JmhzTransportException(
                'jmhz_protocol_period_invalid',
                "Údaj `{$element}` v protokolu není v přípustném rozsahu.",
            );
        }

        return (int) $value;
    }

    private function timestamp(DOMXPath $xpath, string $expression): ?string
    {
        $value = trim($this->text($xpath, $expression));

        return $value === '' ? null : $value;
    }

    private function intAttribute(DOMElement $element, string $name): int
    {
        $value = trim($element->getAttribute($name));
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new JmhzTransportException(
                'jmhz_protocol_unreadable',
                "Atribut `{$name}` protokolu není celé číslo.",
            );
        }

        return (int) $value;
    }
}
