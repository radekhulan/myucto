<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

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
     * GovTalk `Class` → `Message/@eType` vnořené ČSSZ obálky. Zdroj je
     * `CSSZSubmClasses.pdf`, sloupec „Envelope 1.2 eType". Viz
     * {@see forSubmissionClass()}, kde je i důvod, proč to nesmí být konstanta.
     */
    private const ENVELOPE_TYPES = [
        'CSSZ_JMHZ' => 'JMHZ25',
        'CSSZ_REGZEC' => 'REGZEC25',
        'CSSZ_PREZEC' => 'PREZEC26',
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
     *
     * Dokud tenhle výběr nebyl, stavěla se obálka registračního podání pořád
     * přes `documented()`, takže na VREP odcházelo `Class="CSSZ_REGZEC"` s tělem
     * označeným `eType="JMHZ25"`. Hlavička a obsah si tím protiřečí a ČSSZ
     * takové podání nemá jak zpracovat — přitom lokální XSD i katalog kontrol
     * projdou, protože ani jedno na obálku nedosáhne.
     */
    public static function forSubmissionClass(string $submissionClass): self
    {
        $envelopeType = self::ENVELOPE_TYPES[$submissionClass] ?? null;
        if ($envelopeType === null) {
            throw new JmhzTransportException(
                'jmhz_govtalk_envelope_type_unknown',
                'Pro tenhle druh podání není doložený `eType` ČSSZ obálky.',
            );
        }

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

    /** Doložený `eType` ČSSZ obálky pro `Class`, viz {@see forSubmissionClass()}. */
    public static function envelopeTypeFor(string $submissionClass): ?string
    {
        return self::ENVELOPE_TYPES[$submissionClass] ?? null;
    }

    /** Je `eType` jedna z doložených agend? Rozhoduje, jestli má smysl hlídat záměnu. */
    public static function isCatalogEnvelopeType(string $envelopeType): bool
    {
        return in_array($envelopeType, self::ENVELOPE_TYPES, true);
    }
}
