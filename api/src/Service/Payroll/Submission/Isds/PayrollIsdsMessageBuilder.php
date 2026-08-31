<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Isds;

use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * Z čeho se skládá datová zpráva ISDS s mzdovým podáním.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co je obsahem zprávy — a co NENÍ
 * ═══════════════════════════════════════════════════════════════════════════
 * Podávací a dotazovací protokol ČSSZ v1.47, kapitola „ISDS → Komunikační vzor“
 * (strana 24 z 51):
 *
 * > „E-podání pro ČSSZ je zpráva ISDS, jejímž obsahem je jedna nebo více příloh
 * > ve formátu XML odpovídající schématu některého z typů podání […] (tzv.
 * > ‚holé XML podání‘). Pouze takové datové zprávy ISDS budou zpracovány
 * > automaticky systémem pro zpracování e-podání na ČSSZ.“
 *
 * Přílohou je tedy HOLÁ DATOVÁ VĚTA. Proti cestě VREP odpadá všechno ostatní:
 *
 * | vrstva                        | VREP | ISDS („holé XML“) |
 * |-------------------------------|------|-------------------|
 * | GovTalk obálka                | ano  | **ne**            |
 * | podpis kvalifikovaným certif. | ano  | **ne**            |
 * | šifrování na `DIS.CSSZ.2025`  | ano  | **ne**            |
 * | gzip                          | ano  | **ne**            |
 * | base64                        | ano  | **ne**            |
 *
 * Protokol (strana 47) k tomu výslovně varuje, že holé XML není komprimováno,
 * šifrováno ani podepsáno. Zastupuje je kanál sám: ISDS je šifrovaný přenos a
 * ČSSZ podle strany 23 provádí autentizaci a autorizaci „na základě identifikace
 * datové schránky odesílatele“. Přidat sem podpis nebo šifru by nebylo „bezpečněji“
 * — byla by to nedoložená odchylka, kterou by automatické zpracování odmítlo.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Přílohou je TENTÝŽ zmrazený artefakt jako u VREP
 * ═══════════════════════════════════════════════════════════════════════════
 * Builder žádné XML nestaví — dostane bajty zmrazeného artefaktu podání a jen je
 * zabalí. To je záměr, ne úspora: kdyby si ISDS cesta sestavovala vlastní
 * dokument, vznikly by pod jedním podáním dvě různé pravdy s různými GUIDy a
 * duplicitu přijatého podání nelze u ČSSZ vzít zpět. Volba kanálu tak nemůže
 * vyrobit druhé podání, druhou povinnost ani druhý termín.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Pole obálky
 * ═══════════════════════════════════════════════════════════════════════════
 * Protokol (strana 24): „Obsah polí v obálce datové zprávy […] není pro
 * zpracování relevantní (pole věc, zmocnění, k rukám apod.), mohou být nastavena
 * libovolně.“ ČSSZ tedy podle věci nic neřídí — vyplňuje se pro ČLOVĚKA, který
 * zprávu uvidí ve schránce, a proto nese agendu i období.
 *
 * Věc i adresát proto přicházejí Z AGENDY ({@see PayrollIsdsAgenda}), ne jako
 * konstanta v tomhle souboru: builder původně psal do věci „Jednotné měsíční
 * hlášení zaměstnavatele“ natvrdo, takže by hlášení o nemoci dorazilo pod cizím
 * názvem a účetní by ho ve schránce hledala podle nesprávného textu.
 *
 * Co relevantní JE, je `dmSenderIdent` (spisová značka odesílatele): podle strany
 * 24 ho ČSSZ v odpovědi vrátí v `RecipientIdent`. Je to jediný údaj, který si
 * volíme sami a dostaneme zpátky, takže na něm stojí párování odpovědi
 * i dohledání po přerušeném odeslání ({@see \MyInvoice\Service\Submission\Channel\Isds\IsdsChannel::probe()}).
 */
final readonly class PayrollIsdsMessageBuilder
{
    /**
     * ISDS omezuje `dmSenderIdent` na 50 znaků. Delší značka by se uřízla a
     * dohledání odeslané zprávy by přestalo fungovat právě po timeoutu, tedy
     * přesně tehdy, kdy je potřeba.
     */
    public const MAX_SENDER_IDENT = 50;

    /** Přílohou smí být podle protokolu jedině XML. */
    public const ATTACHMENT_MIME = 'application/xml';

    public function build(
        string $frozenArtifactBytes,
        PayrollIsdsAgenda $agenda,
        PayrollIsdsRecipient $recipient,
        ?string $variableSymbol,
        string $periodLabel,
        string $correlationReference,
    ): PayrollIsdsMessage {
        if (trim($frozenArtifactBytes) === '') {
            throw new SubmissionChannelException(
                'payroll_isds_payload_empty',
                'Do datové schránky nelze odeslat prázdnou datovou větu.',
                422,
            );
        }
        // Příloha musí být XML, ne cokoliv, co se za ni vydává. Kdyby sem přišel
        // zip nebo podepsaná obálka, ČSSZ by zprávu nezpracovala automaticky a
        // podání by tiše uvázlo — proto raději hlasitě tady.
        if (!str_starts_with(ltrim($frozenArtifactBytes), '<')) {
            throw new SubmissionChannelException(
                'payroll_isds_payload_not_xml',
                'Příloha pro datovou schránku musí být holé XML datové věty.',
                422,
            );
        }

        return new PayrollIsdsMessage(
            recipient: $recipient,
            subject: $this->subject($agenda, $periodLabel, $variableSymbol),
            senderIdent: $this->senderIdent($correlationReference),
            attachmentFilename: $this->filename(
                $agenda->code,
                $variableSymbol,
                $periodLabel,
            ),
            attachmentMimeType: self::ATTACHMENT_MIME,
            attachmentBytes: $frozenArtifactBytes,
        );
    }

    /**
     * Věc zprávy. ČSSZ ji ignoruje, člověk ne — ve schránce je to jediné, podle
     * čeho pozná, KTERÉ podání a které období odešlo.
     *
     * Veřejná proto, že věc se musí znát DŘÍV než spisová značka: tu přiděluje
     * až fronta podání při zařazení, kdežto věc do zařazení vstupuje.
     *
     * Variabilní symbol je volitelný: nese ho hlavička JMHZ i datové věty
     * NEMPRI/HZUPN, ale kdyby v některé chyběl, je poctivější věc bez něj než
     * věc s prázdnou hodnotou, která vypadá jako chyba přenosu.
     */
    public function subject(
        PayrollIsdsAgenda $agenda,
        string $periodLabel,
        ?string $variableSymbol,
    ): string {
        $subject = sprintf(
            '%s - %s za %s',
            $agenda->code,
            $agenda->label,
            $periodLabel,
        );

        return $variableSymbol === null || trim($variableSymbol) === ''
            ? $subject
            : $subject . ', VS ' . trim($variableSymbol);
    }

    /**
     * Název přílohy je deterministický, protože artefakt je zmrazený: totéž
     * podání musí dát tentýž soubor, jinak by se dvě stažení nedala porovnat.
     */
    private function filename(
        string $agendaCode,
        ?string $variableSymbol,
        string $periodLabel,
    ): string {
        return sprintf(
            '%s_%s_%s.xml',
            $this->slug($agendaCode),
            $this->slug($variableSymbol ?? ''),
            $this->slug($periodLabel),
        );
    }

    private function senderIdent(string $correlationReference): string
    {
        $value = trim($correlationReference);
        if ($value === '') {
            throw new SubmissionChannelException(
                'payroll_isds_sender_ident_missing',
                'Podání nemá spisovou značku, takže by odpověď z datové schránky'
                    . ' nešlo přiřadit zpět.',
                422,
            );
        }
        // Ořezat by znamenalo tiše rozbít párování odpovědi. Radši odmítnout.
        if (strlen($value) > self::MAX_SENDER_IDENT) {
            throw new SubmissionChannelException(
                'payroll_isds_sender_ident_too_long',
                'Spisová značka podání je delší než 50 znaků, které datová'
                    . ' schránka připouští.',
                422,
            );
        }

        return $value;
    }

    /** Název souboru musí projít ISDS i souborovým systémem uživatele. */
    private function slug(string $value): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', trim($value)) ?? '';
        $slug = trim($slug, '-');

        return $slug === '' ? 'x' : $slug;
    }
}
