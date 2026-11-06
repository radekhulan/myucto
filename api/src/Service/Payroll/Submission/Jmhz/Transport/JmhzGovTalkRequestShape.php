<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;

/**
 * Vědomé, explicitní prohlášení tvaru ODESÍLANÉ GovTalk obálky.
 *
 * Tvar je doložený podacím protokolem ČSSZ a OVĚŘENÝ ODESLÁNÍM: testovací VREP
 * podání přijal (`Qualifier=acknowledgement`) a přidělil CorrelationID.
 *
 * Objekt zůstává povinný i tak: `JmhzGovTalkEnvelope` bez něj obálku nepostaví.
 * Kdo ho vytvoří, prohlašuje, že tvar ověřil — `documented()` je ta prohlášená
 * varianta, ne výchozí hodnota, kterou by šlo použít omylem.
 */
final readonly class JmhzGovTalkRequestShape
{
    private const TOKEN = '/^[A-Za-z][A-Za-z0-9._-]{0,63}$/D';

    /**
     * Formulář (`Message/@eType` vnořené ČSSZ obálky) → GovTalk `Class`.
     * Zdroj je `CSSZSubmClasses.pdf` (Přehled obálek ČSSZ, stažen 8. 10. 2026,
     * shodný s kopií z 15. 8. 2026; `private/normy/zdroje/vrep/`), sloupce
     * „GOVTALK CLASS" a „Envelope 1.2 eType".
     *
     * Klíčem je FORMULÁŘ, ne třída: NEMPRI25 i HZUPN20 sdílejí jedinou třídu
     * `CSSZ_NEM_PRI` a liší se jen `eType`. Mapa třída → eType by jednu z nich
     * tiše přepsala a obálka HZUPN by odešla s tělem označeným NEMPRI25.
     */
    private const FORM_CLASSES = [
        'JMHZ25' => 'CSSZ_JMHZ',
        'REGZEC25' => 'CSSZ_REGZEC',
        // Verze 2027 (příručka testování změn 2027, kap. 3.1 a 13.2): Class
        // beze změny, jiný eType. PREZEC27 a DZMH27 tu schválně nejsou, jejich
        // eType doložený není (O-4) a obálka se pro ně proto nepostaví.
        'JMHZ27' => 'CSSZ_JMHZ',
        'REGZEC27' => 'CSSZ_REGZEC',
        'PREZEC26' => 'CSSZ_PREZEC',
        'NEMPRI25' => 'CSSZ_NEM_PRI',
        'HZUPN20' => 'CSSZ_NEM_PRI',
        'OZUSPOJ23' => 'CSSZ_OZUSPOJ',
    ];

    public function __construct(
        public string $submitQualifier,
        public string $pollQualifier,
        public string $function,
        public string $closeFunction,
        public string $variableSymbolKeyType,
        public string $bodyEnvelopeVersion,
        public string $bodyEnvelopeType,
        public string $sourceReference,
    ) {
        foreach ([
            'submitQualifier' => $submitQualifier,
            'pollQualifier' => $pollQualifier,
            'function' => $function,
            'closeFunction' => $closeFunction,
            'variableSymbolKeyType' => $variableSymbolKeyType,
            'bodyEnvelopeType' => $bodyEnvelopeType,
        ] as $field => $value) {
            if (preg_match(self::TOKEN, $value) !== 1) {
                throw new JmhzTransportException(
                    'jmhz_govtalk_shape_invalid',
                    "Hodnota `{$field}` v prohlášeném tvaru GovTalk obálky není platný token.",
                );
            }
        }
        if (preg_match('/^[0-9]+(\.[0-9]+)*$/D', $bodyEnvelopeVersion) !== 1) {
            throw new JmhzTransportException(
                'jmhz_govtalk_shape_invalid',
                'Verze ČSSZ obálky v prohlášeném tvaru není číselná.',
            );
        }
        // Bez odkazu na zdroj by prohlášení bylo jen dalším odhadem s hezčím
        // jménem — v ledgeru pokusů má být dohledatelné, odkud tvar pochází.
        if (trim($sourceReference) === '') {
            throw new JmhzTransportException(
                'jmhz_govtalk_shape_invalid',
                'Prohlášený tvar GovTalk obálky musí uvádět zdroj, ze kterého byl ověřen.',
            );
        }
    }

    /**
     * Tvar doložený podacím protokolem ČSSZ. Zdroj: „CSSZ Podávací a dotazovací
     * protokol", v1.7 z 11. 2. 2025, kap. „Struktura zprávy" (str. 28–29 a 37),
     * plus zveřejněný vzorek `GovTalkEnvelopeCSSZEnvelope12.xml`. Obojí je
     * v `private/Mzdy/podklady/`.
     *
     * Doslovné hodnoty z protokolu:
     * - `Qualifier` = „Rozlišení funkce (request, poll, acknowledgement,
     *   response, error)" → odeslání `request`, dotaz na stav `poll`,
     * - `Function` = „(submit, delete)" → odeslání `submit`,
     * - variabilní symbol patří do `GovTalkDetails/Keys/Key[@Type="vars"]`;
     *   VREP nevyžaduje `Header/SenderDetails`, ale u podání vázaných na
     *   organizaci vyžaduje právě tenhle klíč,
     * - vnořená ČSSZ obálka má `version="1.2"`; `eType` pro JMHZ je `JMHZ25`
     *   (`CSSZSubmClasses.pdf` a dokumentace MPSV k JMHZ).
     *
     * **Pozor na záměnu:** MPSV provozuje ještě B2B bránu s vlastní GovTalk
     * obálkou (`Class=MPSV`, klíč `Type="ico"`, bez PKCS#7, `encrypted="no"`).
     * To je jiné API a pro JMHZ se použít nesmí.
     */
    public static function documented(): self
    {
        return self::forSubmissionClass('CSSZ_JMHZ');
    }

    /**
     * `eType` NENÍ pro všechny agendy stejné.
     *
     * `CSSZSubmClasses.pdf` (`private/Mzdy/podklady/`) má pro každý tiskopis
     * vlastní řádek a sloupec „Envelope 1.2 eType" v něm nese NÁZEV FORMULÁŘE,
     * ne druh GovTalk třídy:
     *
     * | Form | GOVTALK CLASS | Envelope 1.2 eType |
     * |---|---|---|
     * | JMHZ25 | `CSSZ_JMHZ` | `JMHZ25` |
     * | REGZEC25 | `CSSZ_REGZEC` | `REGZEC25` |
     * | PREZEC26 | `CSSZ_PREZEC` | `PREZEC26` |
     * | NEMPRI25 | `CSSZ_NEM_PRI` | `NEMPRI25` |
     * | HZUPN20 | `CSSZ_NEM_PRI` | `HZUPN20` |
     * | OZUSPOJ23 | `CSSZ_OZUSPOJ` | `OZUSPOJ23` |
     *
     * NEMPRI25 a HZUPN20 mají tutéž třídu, takže pro ně se tvar staví jen
     * {@see forForm()}; tady skončí výjimkou.
     *
     * Dokud tenhle výběr nebyl, stavěla se obálka registračního podání pořád
     * přes `documented()`, takže na VREP odcházelo `Class="CSSZ_REGZEC"` s tělem
     * označeným `eType="JMHZ25"`. Hlavička a obsah si tím protiřečí a ČSSZ
     * takové podání nemá jak zpracovat — přitom lokální XSD i katalog kontrol
     * projdou, protože ani jedno na obálku nedosáhne.
     */
    public static function forSubmissionClass(
        string $submissionClass,
        JmhzSpecVersion $version = JmhzSpecVersion::V2026,
    ): self {
        $envelopeType = self::envelopeTypeFor($submissionClass, $version);
        if ($envelopeType === null) {
            // Neznámá třída, nebo třída sdílená víc formuláři (CSSZ_NEM_PRI):
            // tam se `eType` z třídy odvodit nedá a musí přijít formulář.
            throw new JmhzTransportException(
                'jmhz_govtalk_envelope_type_unknown',
                'Pro tenhle druh podání není doložený `eType` ČSSZ obálky.',
            );
        }

        return self::forForm($envelopeType);
    }

    /**
     * Tvar obálky pro konkrétní formulář (`eType`). Jediná cesta pro třídy,
     * které sdílí víc formulářů (`CSSZ_NEM_PRI` = NEMPRI25 a HZUPN20).
     */
    public static function forForm(string $form): self
    {
        if (!isset(self::FORM_CLASSES[$form])) {
            throw new JmhzTransportException(
                'jmhz_govtalk_envelope_type_unknown',
                'Pro tenhle druh podání není doložený `eType` ČSSZ obálky.',
            );
        }
        $envelopeType = $form;

        return new self(
            submitQualifier: 'request',
            pollQualifier: 'poll',
            function: 'submit',
            closeFunction: 'delete',
            variableSymbolKeyType: 'vars',
            bodyEnvelopeVersion: '1.2',
            bodyEnvelopeType: $envelopeType,
            sourceReference: 'CSSZ Podavaci a dotazovaci protokol v1.7 (11. 2. 2025),'
                . ' kap. Struktura zpravy; CSSZSubmClasses.pdf',
        );
    }

    /**
     * Tvar obálky pro zmrazenou datovou větu: `eType` se bere z ročníku, který
     * nese jmenný prostor kořene těla ({@see JmhzSpecVersion::fromDocumentNamespace()}).
     * Hlavička obálky tak nemůže tvrdit jiný ročník, než jaký je uvnitř.
     * U formulářů, jejichž jmenný prostor ročník nerozlišuje (PREZEC, NEMPRI,
     * OZUSPOJ), platí totéž co {@see forSubmissionClass()}; verzi 2027 u nich
     * hlídá už sestavení podle data události.
     */
    public static function forPayload(string $submissionClass, string $payloadXml): self
    {
        $version = null;
        if (JmhzSpecVersion::isVersionedClass($submissionClass)) {
            $previous = libxml_use_internal_errors(true);
            $reader = new \XMLReader();
            $namespace = null;
            if ($reader->XML($payloadXml, null, LIBXML_NONET)) {
                while ($reader->read()) {
                    if ($reader->nodeType === \XMLReader::ELEMENT) {
                        $namespace = $reader->namespaceURI;
                        break;
                    }
                }
                $reader->close();
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $version = is_string($namespace) ? JmhzSpecVersion::fromDocumentNamespace($namespace) : null;
        }

        return self::forSubmissionClass($submissionClass, $version ?? JmhzSpecVersion::V2026);
    }

    /**
     * Doložený `eType` ČSSZ obálky pro `Class` ve verzi specifikace, viz
     * {@see forSubmissionClass()} a {@see JmhzSpecVersion::envelopeTypeFor()}.
     * U třídy sdílené víc formuláři vrací `null` — jednoznačná odpověď není;
     * stejně tak u verze, pro kterou `eType` formuláře doložený není.
     */
    public static function envelopeTypeFor(
        string $submissionClass,
        JmhzSpecVersion $version = JmhzSpecVersion::V2026,
    ): ?string {
        if (JmhzSpecVersion::isVersionedClass($submissionClass)) {
            return $version->envelopeTypeFor($submissionClass);
        }
        $forms = array_keys(self::FORM_CLASSES, $submissionClass, true);

        return count($forms) === 1 ? $forms[0] : null;
    }

    /** Doložená GovTalk `Class` formuláře (`eType`), nebo `null`. */
    public static function classForForm(string $form): ?string
    {
        return self::FORM_CLASSES[$form] ?? null;
    }

    /** Je `eType` jedna z doložených agend? Rozhoduje, jestli má smysl hlídat záměnu. */
    public static function isCatalogEnvelopeType(string $envelopeType): bool
    {
        return isset(self::FORM_CLASSES[$envelopeType]);
    }
}
