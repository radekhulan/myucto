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

    private const CLASSES = [
        'CSSZ_JMHZ',
        'CSSZ_REGZEC',
        'CSSZ_PREZEC',
        'CSSZ_NEM_PRI',
        'CSSZ_OZUSPOJ',
    ];

    /**
     * Registrační protokoly mají jiný tvar než protokol JMHZ, viz
     * {@see parseRegistrationSubmission()}. Klíčem je `Class`, hodnotou jediný
     * doložený `Item/@subtype` téže agendy.
     */
    private const REGISTRATION_SUBTYPES = [
        // REGZEC27 = verze 2027 pro události od 1. 1. 2027 (JmhzSpecVersion).
        // Tvar protokolu 2027 zatím doložený není; přijme se jen tentýž tvar
        // s ročníkem 2027 a jeden protokol nesmí ročníky míchat.
        'CSSZ_REGZEC' => ['REGZEC25', 'REGZEC27'],
        'CSSZ_PREZEC' => ['PREZEC26'],
    ];

    /**
     * Podání jednoho formuláře (NEMPRI25 a HZUPN20 pod `CSSZ_NEM_PRI`,
     * OZUSPOJ23 pod `CSSZ_OZUSPOJ`), viz {@see parseCsszFormSubmission()}.
     * Klíčem je `Class`, hodnotou doložené `Item/@subtype` téže třídy.
     */
    private const CSSZ_FORM_SUBTYPES = [
        'CSSZ_NEM_PRI' => ['NEMPRI25', 'HZUPN20'],
        'CSSZ_OZUSPOJ' => ['OZUSPOJ23'],
    ];

    /**
     * Protokol k podání jednoho formuláře, který neodpovídá doloženému tvaru.
     * Odpověď se nesmí vyložit podle JMHZ ani zahodit: parser ji pojmenuje
     * tímhle kódem a transport ji uloží k ručnímu vyřízení.
     */
    public const UNDOCUMENTED_SHAPE_CODE = 'jmhz_protocol_shape_undocumented';
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
        if (isset(self::CSSZ_FORM_SUBTYPES[$class])) {
            try {
                if (!$result instanceof DOMElement) {
                    throw new JmhzTransportException(
                        'jmhz_protocol_unreadable',
                        'Protokol neobsahuje element ProcessingResult.',
                    );
                }

                return $this->parseCsszFormSubmission($result, $class, $qualifier, $correlation);
            } catch (JmhzTransportException $exception) {
                throw new JmhzTransportException(
                    self::UNDOCUMENTED_SHAPE_CODE,
                    'ČSSZ vrátila protokol k podání ' . $class . ', který neodpovídá'
                        . ' doloženému tvaru (' . $exception->getMessage() . ').'
                        . ' Aplikace ho uloží k podání a výsledek zpracování'
                        . ' zapište podle protokolu ručně.',
                );
            }
        }

        if (!$result instanceof DOMElement) {
            throw new JmhzTransportException(
                'jmhz_protocol_unreadable',
                'Protokol z dílčího podání neobsahuje element ProcessingResult.',
            );
        }
        if (isset(self::REGISTRATION_SUBTYPES[$class])) {
            return $this->parseRegistrationSubmission(
                $result,
                $class,
                $qualifier,
                $correlation,
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
     * Protokol k registraci zaměstnance (PREZEC, REGZEC).
     *
     * Zdroj tvaru: MPSV „Interpretace protokolů pro JMHZ — REGZEC" v1.0
     * (10/2025, `private/normy/zdroje/prezec26/doplnkove/protokoly_regzec_v1.0`)
     * a protokoly zkušebních podání do testovacího prostředí ČSSZ z 8. 10. 2026.
     * Od protokolu JMHZ se liší ve čtyřech věcech a podle JMHZ se číst nesmí:
     *
     * 1. `Item/@subtype` nese název formuláře (`REGZEC25`, `PREZEC26`), ne
     *    `FORM`/`SOUHRN`/`PVPOJ`.
     * 2. `Item/@sqnr` je pořadí věty v podání (atribut `employee/@sqnr`), ne
     *    GUID formuláře. GUID nese jen PREZEC, a to v `identifier`.
     * 3. `identifier` má tvar „RČ;IKMPSV;IDPPV" u REGZEC akce 1, „RČ" u ostatních
     *    akcí REGZEC a „RČ;GUID" u PREZEC; každá část smí být prázdná.
     * 4. Chybové kódy nejsou kontroly katalogu MH (např. `103901602` post DIS
     *    validace evidence ČSSZ, `604`, `306`); `errNum` položky je někdy jen
     *    poslední trojčíslí kódu z textu a `errNumber` souhrnu číslo chyby
     *    GovTalk. O stavu podání kódy nerozhodují — rozhoduje `result`.
     *
     * Výklad stavu je stejný jako u JMHZ s jedním balíkem (obecná kontrola
     * zamítá celé podání, všechny formuláře chybné zamítají celé podání, jinak
     * částečné přijetí). Zamítnuté podání se podle Interpretace posílá znovu
     * celé, takže u něj se nepřijatým vede i formulář s `result="OK"`.
     */
    private function parseRegistrationSubmission(
        DOMElement $result,
        string $class,
        string $qualifier,
        string $correlation,
    ): JmhzProtocolReport {
        $outcome = $this->assertOutcome($result->getAttribute('result'));
        $errors = $this->parseRegistrationErrors(
            $result->getAttribute('errMsg'),
            $result->getAttribute('errNumber'),
            false,
        );
        $subtypes = self::REGISTRATION_SUBTYPES[$class];
        $subtype = null;
        $parts = [];
        $sequences = [];
        foreach ($result->getElementsByTagName('Item') as $item) {
            $itemSubtype = trim($item->getAttribute('subtype'));
            if (!in_array($itemSubtype, $subtypes, true)
                || ($subtype !== null && $subtype !== $itemSubtype)
            ) {
                throw new JmhzTransportException(
                    'jmhz_protocol_part_unknown',
                    'Část registračního protokolu nemá doložený druh `'
                        . implode('`/`', $subtypes) . '`.',
                );
            }
            $subtype = $itemSubtype;
            $itemOutcome = $this->assertOutcome($item->getAttribute('result'));
            $sequence = trim($item->getAttribute('sqnr'));
            $itemErrors = $this->parseRegistrationErrors(
                $item->getAttribute('errMsg'),
                $item->getAttribute('errNum'),
                true,
            );
            $status = $itemOutcome === 'OK'
                ? JmhzSubmissionStatus::ProcessedAndComplete
                : JmhzSubmissionStatus::Rejected;
            if ($sequence === '') {
                $parts[] = new JmhzProtocolPart(
                    JmhzProtocolPartKind::General,
                    $status,
                    null,
                    null,
                    null,
                    $itemErrors,
                );
                continue;
            }
            if (preg_match('/^[1-9][0-9]{0,3}$/D', $sequence) !== 1
                || isset($sequences[$sequence])
            ) {
                throw new JmhzTransportException(
                    'jmhz_protocol_form_unidentified',
                    'Formulář registračního protokolu nemá jednoznačné pořadové číslo.',
                );
            }
            $sequences[$sequence] = true;
            [$personReference, $employmentReference, $formGuid] =
                $this->registrationIdentifier($class, $item->getAttribute('identifier'));
            if ($formGuid === null && $class === 'CSSZ_PREZEC' && $itemOutcome === 'OK') {
                // Na GUID přijaté P1 se odkazuje ukončení P2. Odvozený klíč
                // by P2 poslal s referencí, kterou ČSSZ nezná.
                throw new JmhzTransportException(
                    'jmhz_protocol_form_unidentified',
                    'Přijatý formulář PREZEC v protokolu neuvádí GUID formuláře.',
                );
            }
            $parts[] = new JmhzProtocolPart(
                JmhzProtocolPartKind::Form,
                $status,
                $formGuid ?? self::registrationFormKey($class, $correlation, $sequence),
                $personReference,
                $employmentReference,
                $itemErrors,
            );
        }
        $status = $this->derivePartialStatus(
            $outcome,
            $parts,
            1,
            $this->intAttribute($result, 'countWar'),
        );
        $rejected = $status === JmhzSubmissionStatus::Rejected;
        if (($qualifier === self::QUALIFIER_REJECTED) !== $rejected) {
            throw new JmhzTransportException(
                'jmhz_protocol_qualifier_conflict',
                'Obálka registračního protokolu hlásí jiný výsledek než ProcessingResult.',
            );
        }
        $formStatuses = [];
        foreach ($parts as $index => $part) {
            if ($part->kind !== JmhzProtocolPartKind::Form || $part->formGuid === null) {
                continue;
            }
            if ($rejected && $part->status !== JmhzSubmissionStatus::Rejected) {
                $part = new JmhzProtocolPart(
                    $part->kind,
                    JmhzSubmissionStatus::Rejected,
                    $part->formGuid,
                    $part->ikMpsv,
                    $part->idPpv,
                    $part->errors,
                );
                $parts[$index] = $part;
            }
            $formStatuses[$part->formGuid] = $part->status;
        }

        return new JmhzProtocolReport(
            JmhzProtocolKind::PartialSubmission,
            $class,
            $status,
            $this->correlationReference($correlation),
            array_values($parts),
            $errors,
            $formStatuses,
        );
    }

    /**
     * @return array{0:?string,1:?string,2:?string} IK MPSV, ID PPV, GUID formuláře
     */
    private function registrationIdentifier(string $class, string $identifier): array
    {
        $trimmed = trim($identifier);
        if ($trimmed === '') {
            return [null, null, null];
        }
        $pieces = array_map('trim', explode(';', $trimmed));
        $expected = $class === 'CSSZ_PREZEC' ? [1, 2] : [1, 3];
        if (!in_array(count($pieces), $expected, true)) {
            throw new JmhzTransportException(
                'jmhz_protocol_identifier_unreadable',
                'Atribut `identifier` registračního protokolu nemá doložený tvar.',
            );
        }
        // Rodné číslo na prvním místě se nikam nepřebírá: osobu podání
        // identifikuje už zmrazená věta a protokol ho jen zopakuje.
        if ($class === 'CSSZ_PREZEC') {
            return [null, null, $this->formGuid($pieces[1] ?? '')];
        }
        $person = ($pieces[1] ?? '') === '' ? null : $pieces[1];
        $employment = ($pieces[2] ?? '') === '' ? null : $pieces[2];
        foreach ([$person, $employment] as $reference) {
            if ($reference !== null && preg_match('/^[0-9]{1,32}$/D', $reference) !== 1) {
                throw new JmhzTransportException(
                    'jmhz_protocol_identifier_unreadable',
                    'OIČ nebo ID PPV v registračním protokolu nemá tvar čísla.',
                );
            }
        }

        return [$person, $employment, null];
    }

    /**
     * Klíč výsledku formuláře, když ho ČSSZ nevrací (REGZEC nemá GUID věty
     * a protokol páruje formulář jen pořadím). Je deterministický, takže
     * opakované načtení téhož protokolu vede na tentýž klíč; tvar GUID
     * (verze 8, RFC 9562) drží jen kvůli úložišti výsledků formulářů.
     */
    private static function registrationFormKey(
        string $class,
        string $correlation,
        string $sequence,
    ): string {
        $hex = substr(hash('sha256', "cssz-registration-form:{$class}:{$correlation}:{$sequence}"), 0, 32);
        $hex[12] = '8';
        $hex[16] = '89ab'[hexdec($hex[16]) % 4];

        return strtoupper(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ));
    }

    /**
     * Protokol k podání jednoho formuláře: NEMPRI25 a HZUPN20 (`CSSZ_NEM_PRI`),
     * OZUSPOJ23 (`CSSZ_OZUSPOJ`).
     *
     * Zdroj tvaru: protokoly zkušebních podání do testovacího prostředí ČSSZ
     * z 8. 10. 2026 (kolo 2). Tvar je registrační protokol s jediným
     * formulářem:
     *
     * 1. `Item/@subtype` nese název formuláře; obecná položka má prázdné `sqnr`,
     *    formulář pořadí věty (OZUSPOJ23 ho čísluje od nuly).
     * 2. `identifier` formuláře je rodné číslo; nikam se nepřebírá, osobu podání
     *    identifikuje zmrazená věta. GUID formuláře protokol nenese, takže se
     *    výsledek formuláře neukládá zvlášť — podání má formulář jediný.
     * 3. Chyba komunikace (třeba 103 „pověření k e-službě není zaznamenáno
     *    v registru podávajících") podání vůbec nezpracuje: `ProcessingResult`
     *    nese kód v `errNumber` a holý text v `errMsg` a jediná položka je
     *    celá prázdná.
     *
     * Výklad stavu je stejný jako u registrace s jedním formulářem; částečné
     * přijetí u jediného formuláře nastat nemůže, a když z protokolu vyjde,
     * protokol si odporuje. Cokoli mimo tenhle tvar volající pojmenuje jako
     * nedoložený tvar a podání jde k ručnímu vyřízení.
     */
    private function parseCsszFormSubmission(
        DOMElement $result,
        string $class,
        string $qualifier,
        string $correlation,
    ): JmhzProtocolReport {
        $outcome = $this->assertOutcome($result->getAttribute('result'));
        $errors = $this->parseRegistrationErrors(
            $result->getAttribute('errMsg'),
            $result->getAttribute('errNumber'),
            false,
        );
        $subtypes = self::CSSZ_FORM_SUBTYPES[$class];
        $subtype = null;
        $formSeen = false;
        $parts = [];
        foreach ($result->getElementsByTagName('Item') as $item) {
            $itemSubtype = trim($item->getAttribute('subtype'));
            $sequence = trim($item->getAttribute('sqnr'));
            if ($itemSubtype === ''
                && $sequence === ''
                && trim($item->getAttribute('result')) === ''
                && trim($item->getAttribute('identifier')) === ''
                && trim($item->getAttribute('errMsg')) === ''
            ) {
                if ($outcome !== 'ERROR') {
                    throw new JmhzTransportException(
                        'jmhz_protocol_part_unknown',
                        'Přijatý protokol obsahuje prázdnou položku.',
                    );
                }
                continue;
            }
            if (!in_array($itemSubtype, $subtypes, true)
                || ($subtype !== null && $subtype !== $itemSubtype)
            ) {
                throw new JmhzTransportException(
                    'jmhz_protocol_part_unknown',
                    'Položka protokolu nemá doložený druh formuláře.',
                );
            }
            $subtype = $itemSubtype;
            $status = $this->assertOutcome($item->getAttribute('result')) === 'OK'
                ? JmhzSubmissionStatus::ProcessedAndComplete
                : JmhzSubmissionStatus::Rejected;
            $itemErrors = $this->parseRegistrationErrors(
                $item->getAttribute('errMsg'),
                $item->getAttribute('errNum'),
                true,
            );
            if ($sequence === '') {
                $parts[] = new JmhzProtocolPart(
                    JmhzProtocolPartKind::General,
                    $status,
                    null,
                    null,
                    null,
                    $itemErrors,
                );
                continue;
            }
            if (preg_match('/^[0-9]{1,4}$/D', $sequence) !== 1 || $formSeen) {
                throw new JmhzTransportException(
                    'jmhz_protocol_form_unidentified',
                    'Protokol nemá právě jeden formulář s pořadovým číslem.',
                );
            }
            $formSeen = true;
            $parts[] = new JmhzProtocolPart(
                JmhzProtocolPartKind::Form,
                $status,
                null,
                null,
                null,
                $itemErrors,
            );
        }
        if ($outcome === 'OK' && !$formSeen) {
            throw new JmhzTransportException(
                'jmhz_protocol_form_unidentified',
                'Přijatý protokol neuvádí výsledek formuláře.',
            );
        }
        $status = $this->derivePartialStatus(
            $outcome,
            $parts,
            1,
            $this->intAttribute($result, 'countWar'),
        );
        if ($status === JmhzSubmissionStatus::PartiallyAccepted) {
            throw new JmhzTransportException(
                'jmhz_protocol_outcome_conflict',
                'Protokol hlásí chybu, ale jeho jediný formulář je přijatý.',
            );
        }
        if (($qualifier === self::QUALIFIER_REJECTED) !== ($status === JmhzSubmissionStatus::Rejected)) {
            throw new JmhzTransportException(
                'jmhz_protocol_qualifier_conflict',
                'Obálka protokolu hlásí jiný výsledek než ProcessingResult.',
            );
        }

        return new JmhzProtocolReport(
            JmhzProtocolKind::PartialSubmission,
            $class,
            $status,
            $this->correlationReference($correlation),
            $parts,
            $errors,
            [],
        );
    }

    /**
     * Chyby registračního protokolu. Text bývá „PREFIX: kód - popis" (víc
     * chyb oddělených středníkem) i holý popis s kódem jen v `errNum`.
     * Stejně je píší i protokoly k podání jednoho formuláře.
     *
     * @return list<JmhzProtocolError>
     */
    private function parseRegistrationErrors(
        string $message,
        string $declaredCode,
        bool $checkDeclared,
    ): array {
        $normalized = trim($message);
        if ($normalized === '') {
            return [];
        }
        $declared = trim($declaredCode);
        $normalized = preg_replace('/^[A-Za-z0-9_]+:\s*/', '', $normalized) ?? $normalized;
        if (preg_match('/^[0-9]+\s*-\s*/', $normalized) !== 1) {
            if (preg_match('/^[1-9][0-9]{0,9}$/D', $declared) !== 1) {
                throw new JmhzTransportException(
                    'jmhz_protocol_error_message_unreadable',
                    self::unreadableMessage($message),
                );
            }

            return [JmhzProtocolError::fromRegistrationCode((int) $declared, $normalized)];
        }
        $errors = [];
        foreach (preg_split('/;\s*(?=[0-9]+\s*-\s*)/u', $normalized) ?: [] as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            if (preg_match('/^([0-9]+)\s*-\s*(.*)$/us', $segment, $matches) !== 1
                || (int) $matches[1] <= 0
            ) {
                throw new JmhzTransportException(
                    'jmhz_protocol_error_message_unreadable',
                    self::unreadableMessage($message),
                );
            }
            $errors[] = JmhzProtocolError::fromRegistrationCode(
                (int) $matches[1],
                rtrim(trim($matches[2]), ';'),
            );
        }
        if ($errors === []) {
            throw new JmhzTransportException(
                'jmhz_protocol_error_message_unreadable',
                self::unreadableMessage($message),
            );
        }
        // Položka nese v `errNum` celý kód, nebo jen jeho poslední trojčíslí
        // (103901602 → 602). Jiné číslo znamená, že text a kód si odporují.
        $first = $errors[0]->code;
        if ($checkDeclared
            && $declared !== ''
            && $declared !== '0'
            && (int) $declared !== $first
            && (int) $declared !== $first % 1000
        ) {
            throw new JmhzTransportException(
                'jmhz_protocol_error_code_conflict',
                'Deklarovaný kód první chyby neodpovídá chybové hlášce.',
            );
        }

        return $errors;
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

    /**
     * Hláška o nerozebratelném protokolu VČETNĚ toho, co ČSSZ opravdu poslala.
     *
     * Text protokolu je jediné, co říká, co je s podáním špatně. Když se
     * nevejde do očekávaného tvaru „kód - text", nesmí se zahodit: účetní pak
     * čte jen to, že aplikace něčemu nerozumí, a nemá se čeho chytit — ani co
     * poslat na podporu. Kód se z takové hlášky ZÁMĚRNĚ nedohaduje; interpretace
     * zůstává zavřená, viditelnost ne.
     */
    private static function unreadableMessage(string $message): string
    {
        $raw = trim($message);
        if ($raw === '') {
            return 'Protokol ČSSZ neobsahuje čitelnou chybovou hlášku.';
        }
        if (mb_strlen($raw) > 500) {
            $raw = mb_substr($raw, 0, 500) . '…';
        }

        return 'Chybovou hlášku protokolu nelze rozebrat na kód a text.'
            . ' ČSSZ vrátila: ' . $raw;
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
                    self::unreadableMessage($message),
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
                self::unreadableMessage($message),
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
