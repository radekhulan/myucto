<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

/**
 * Co aplikace umí přečíst z potvrzení, které se vrátí po podání — podle agendy.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč je tenhle výčet potřeba
 * ═══════════════════════════════════════════════════════════════════════════
 * Dodejka z datové schránky je jedna a tatáž bez ohledu na agendu: ZFO
 * s `dmDeliveryTime` a `dmAcceptanceTime`, které čte
 * {@see \MyInvoice\Service\Document\ZfoExtractor}. Ta část tedy „chybí" jen
 * zdánlivě — funguje pro JMHZ i pro cokoliv jiného, co odejde přes
 * `submission_outbox`.
 *
 * Co se ale mezi agendami LIŠÍ, je druhé, obsahové potvrzení: protokol o
 * zpracování. Ten má doložený tvar jen u ČSSZ (JMHZ/REGZEC — parsuje
 * {@see \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolParser})
 * a u EPO ({@see \MyInvoice\Service\Epo\EpoConfirmationParser}). U zdravotních
 * pojišťoven a u daňových podání poslaných datovou schránkou žádný doložený
 * strojový tvar odpovědi nemáme.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ Formát se NEODHADUJE
 * ═══════════════════════════════════════════════════════════════════════════
 * Napsat parser podle jednoho vzorku, který někdo viděl, by znamenalo tvrdit
 * „podání přijato" na základě dohadu o cizím schématu. Chybný parser je horší
 * než žádný: žádný mlčí a věc řeší člověk, chybný tiše uzavře povinnost, která
 * uzavřená není.
 *
 * Proto tenhle výčet nedoplňuje chybějící parsery, ale dělá z jejich absence
 * VIDITELNÝ, pojmenovaný stav. UI podle něj řekne „potvrzení k téhle agendě
 * neumíme přečíst, zkontrolujte ho ručně" místo aby nic neukázalo.
 */
enum AgendaReceiptCapability: string
{
    /** Umíme přečíst protokol o zpracování a poznat z něj výsledek. */
    case ProcessingProtocol = 'processing_protocol';

    /**
     * Umíme přečíst jen dodejku z datové schránky — tedy DORUČENÍ.
     * O vyřízení úřadem z ní nic neplyne.
     */
    case DeliveryReceiptOnly = 'delivery_receipt_only';

    /**
     * Doložený tvar potvrzení nemáme. Nic se nečte, výsledek zadává člověk.
     * Není to chyba k opravě naslepo — je to čekání na specifikaci.
     */
    case Undocumented = 'undocumented';

    /**
     * Přiřazení agendy ke schopnosti.
     *
     * Klíč je `agenda_code` ze `submission_outbox`. Neznámá agenda spadne na
     * {@see self::Undocumented} — fail-closed, protože tvrdit u neznámé agendy
     * schopnost, kterou nemáme, je právě ta chyba, které se tu vyhýbáme.
     */
    public static function forAgenda(string $agendaCode): self
    {
        return match (strtoupper(trim($agendaCode))) {
            // ČSSZ přes VREP/APEP vrací protokol o zpracování s doloženým XSD.
            'JMHZ', 'JMHZ25', 'REGZEC', 'REGZEC25', 'PREZEC', 'PREZEC26',
            'REGZEL', 'REGZEL26', 'REGZELDOPL25', 'DZMH',
            'OZUSPOJ', 'OZUSPOJ23' => self::ProcessingProtocol,
            // NEMPRI a HZUPN mají protokol o zpracování se stejným tvarem;
            // e-podání je ale posíláme datovou schránkou, takže `forChannel()`
            // z nich udělá DeliveryReceiptOnly. Protokol přijde do schránky
            // jako samostatná zpráva a naimportovat ho zatím neumíme — dokud
            // to neplatí, výsledek zadává člověk.
            'NEMPRI', 'NEMPRI25', 'HZUPN', 'HZUPN20' => self::ProcessingProtocol,
            // EPO vrací potvrzení s podacím číslem — ale jen když jde přes EPO.
            // Totéž podání odeslané datovkou takové potvrzení nedostane.
            'DPHDP3', 'DPHKH1', 'DPHSHV', 'DPPDP9', 'DPFDP5', 'DPFDP7',
            'OSSEI1', 'OSVC25' => self::ProcessingProtocol,
            default => self::Undocumented,
        };
    }

    /**
     * Co se dá vyčíst z podání odeslaného konkrétním kanálem.
     *
     * Kanál má přednost před agendou: DPHDP3 poslané přes EPO potvrzení má,
     * totéž DPHDP3 poslané ručně datovou schránkou dostane jen dodejku.
     * Odvozovat schopnost jen z agendy by u druhého případu slibovalo protokol,
     * který nikdy nepřijde.
     */
    public static function forChannel(string $channelCode, string $agendaCode): self
    {
        return match ($channelCode) {
            'epo' => self::forAgenda($agendaCode),
            'vrep_apep' => self::ProcessingProtocol,
            'isds' => self::forAgenda($agendaCode) === self::ProcessingProtocol
                // ČSSZ posílá protokol do datové schránky jako samostatnou
                // zprávu — tu umíme naimportovat. U ostatních agend zůstává
                // z ISDS jen dodejka.
                && in_array(strtoupper(trim($agendaCode)), ['JMHZ', 'JMHZ25', 'REGZEC', 'REGZEC25', 'DZMH'], true)
                    ? self::ProcessingProtocol
                    : self::DeliveryReceiptOnly,
            default => self::Undocumented,
        };
    }

    /** Věta pro uživatele — co od potvrzení čekat a co musí udělat sám. */
    public function sentence(): string
    {
        return match ($this) {
            self::ProcessingProtocol =>
                'Protokol o zpracování k téhle agendě aplikace přečte a výsledek z něj zapíše.',
            self::DeliveryReceiptOnly =>
                'K téhle agendě aplikace přečte jen dodejku z datové schránky, tedy DORUČENÍ. '
                . 'Jestli úřad podání přijal, z ní neplyne — výsledek zkontrolujte a zadejte ručně.',
            self::Undocumented =>
                'Tvar potvrzení pro tuhle agendu nemáme doložený, takže ho aplikace nečte a nic o něm netvrdí. '
                . 'Odpověď úřadu projděte ručně; ticho tady neznamená, že je vyřízeno.',
        };
    }
}
