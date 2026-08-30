<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Kterým kanálem se NEMPRI a HZUPN smí odeslat — a čím je to doložené.
 *
 * Laťka je stejná jako u {@see \MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsurerChannelCatalog}:
 * kanál se otevře jen tehdy, když je doložený z primárního zdroje, a odmítnutí
 * vždy pojmenuje, KTERÁ specifikace chybí — ne obecné „nepodporováno".
 *
 * ## Co je doložené
 *
 * ČSSZ, „Komunikační kanály e-Podání" (podklad uložený 17. 8. 2026,
 * `private/Mzdy/podklady/cssz-komunikacni-kanaly-2026-08-17/`) uvádí v tabulce
 * podporovaných e-Podání u obou agend ANO ve všech třech sloupcích
 * (VREP/APEP, ISDS, PIKR):
 *
 *   NEMPRI25 — „Oznámení zaměstnavatele o žádosti zaměstnance o dávku
 *              (NEMPRI_2025)" — ano / ano / ano
 *   HZUPN    — „Hlášení zaměstnavatele /osoby dobrovolně nemocensky pojištěné/
 *              při ukončení pracovní neschopnosti" — ano / ano / ano
 *
 * a k ISDS dodává: „do specializované datové schránky e-Podání ČSSZ
 * (preferováno): ID schránky: 5ffu6xk, Název schránky: e-podani ČSSZ
 * a/nebo do datových schránek místně příslušné OSSZ/PSSZ/MSSZ."
 *
 * Podávací a dotazovací protokol v1.47 (11. 2. 2025) k tomu u obou agend
 * uvádí sloupec ISDS jako „holé XML", tedy bez GovTalk obálky.
 *
 * ## Co doložené NENÍ
 *
 * Kanál VREP/APEP vyžaduje v GovTalk obálce identifikátor třídy podání
 * (`<Class>`). Protokol v1.47 příklady uvádí jen pro `CSSZ_PRIHL`,
 * `CSSZ_RELDP`, `CSSZ_ONZ` a `CSSZ_HPN`; hodnotu pro NEMPRI25 ani HZUPN20
 * v žádném z připnutých podkladů nemáme. Odhadnout ji je přesně ta chyba,
 * které se modul u JMHZ vyhnul tím, že produkční URL VREP zůstala `null`,
 * dokud ji nepotvrdil nezávislý zdroj. Kanál proto zůstává zavřený.
 *
 * ePortál (PIKR) je ruční kanál s ověřenou identitou podatele; strojové
 * rozhraní pro něj neexistuje, takže se nenabízí ani jako „skoro".
 */
final class SicknessChannelCatalog
{
    /** Kanál, kterým podání odchází, když je otevřený. */
    public const CHANNEL_ISDS = 'isds';

    public const REASON_VREP_CLASS_UNDOCUMENTED =
        'cssz_vrep_submission_class_undocumented';
    public const REASON_PORTAL_MANUAL_ONLY =
        'cssz_eportal_manual_channel_only';
    public const REASON_CHANNEL_UNKNOWN =
        'cssz_channel_unknown';

    /**
     * Specializovaná datová schránka e-Podání ČSSZ. Zdroj je citovaný
     * v docblocku třídy; je to preferovaný cíl, ne jediný přípustný — místně
     * příslušná OSSZ má vlastní schránku a tu volí uživatel v adresáři.
     */
    public const CSSZ_EPODANI_DATA_BOX = '5ffu6xk';

    /** @var list<string> */
    private const DOCUMENTED_CHANNELS = [self::CHANNEL_ISDS];

    /** @return list<string> */
    public function documentedChannels(): array
    {
        return self::DOCUMENTED_CHANNELS;
    }

    public function isDocumented(string $channel): bool
    {
        return in_array($channel, self::DOCUMENTED_CHANNELS, true);
    }

    /**
     * Kanál, kterým se podání této agendy odesílá.
     *
     * Volání je záměrně bez parametru: obě agendy mají tentýž doložený kanál
     * a přetěžovat rozhodnutí druhem tiskopisu by naznačovalo volbu, která
     * neexistuje.
     */
    public function dispatchChannel(): string
    {
        return self::CHANNEL_ISDS;
    }

    /**
     * Fail-closed brána. Nedoložený kanál nikdy nekončí obecnou hláškou —
     * vždy řekne, která specifikace chybí a co s tím může obsluha udělat.
     */
    public function assertDispatchable(string $channel): void
    {
        if ($this->isDocumented($channel)) {
            return;
        }
        [$code, $message] = match ($channel) {
            'vrep_apep' => [
                self::REASON_VREP_CLASS_UNDOCUMENTED,
                'ČSSZ kanál VREP/APEP pro NEMPRI i HZUPN přijímá, ale identifikátor třídy podání '
                . '(prvek Class v GovTalk obálce) pro tyhle dvě agendy není v připnutém Podávacím '
                . 'a dotazovacím protokolu v1.47 uvedený. Hádat ho nebudeme. Odešlete podání datovou '
                . 'schránkou do e-Podání ČSSZ (' . self::CSSZ_EPODANI_DATA_BOX . ') nebo na místně '
                . 'příslušnou OSSZ.',
            ],
            'eportal', 'pikr' => [
                self::REASON_PORTAL_MANUAL_ONLY,
                'ePortál ČSSZ je ruční kanál s ověřenou identitou podatele; strojové rozhraní pro něj '
                . 'neexistuje. Připravené XML stáhněte a nahrajte na ePortálu, nebo ho odešlete datovou '
                . 'schránkou.',
            ],
            default => [
                self::REASON_CHANNEL_UNKNOWN,
                'Pro tenhle kanál nemáme u NEMPRI ani HZUPN doloženou specifikaci podání.',
            ],
        };

        throw new SicknessException($code, $message);
    }
}
