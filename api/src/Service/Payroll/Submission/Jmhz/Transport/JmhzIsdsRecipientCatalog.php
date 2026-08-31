<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsRecipient;

/**
 * Do které datové schránky se JMHZ podává.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč to není literál v kódu
 * ═══════════════════════════════════════════════════════════════════════════
 * Identifikátor datové schránky je sedm znaků bez kontrolní číslice a bez
 * jakékoli vnitřní struktury, takže překlep je pravopisně neviditelný a nedá se
 * odhalit ničím jiným než porovnáním se zdrojem. Odeslané podání navíc nelze
 * vzít zpět; kdyby odešlo do cizí schránky, je z toho únik mzdových údajů všech
 * zaměstnanců a lhůta uplyne nesplněná. Adresáti proto stojí na jednom místě,
 * každý s citací, a testy je drží na doložených hodnotách.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Zdroje
 * ═══════════════════════════════════════════════════════════════════════════
 * - Stránka ČSSZ „Komunikační kanály e-Podání"
 *   (<https://www.cssz.gov.cz/komunikacni-kanaly-e-podani>, staženo 17. 8. 2026,
 *   uloženo v `private/Mzdy/podklady/cssz-komunikacni-kanaly-2026-08-17/`).
 *   Doslova: „Pro podání JMHZ je určena nová datová schránka: ID schránky:
 *   iie254d".
 * - Podávací a dotazovací protokol ČSSZ, verze 1.47 z 11. 2. 2025, kapitola
 *   „Prostředí": produkční `5ffu6xk` („e-podani ČSSZ") na straně 47, testovací
 *   `9tsaf6s` („e-podání TEST") na straně 48.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč má JMHZ přednost před obecnou schránkou
 * ═══════════════════════════════════════════════════════════════════════════
 * Protokol označuje `5ffu6xk` za preferovanou schránku e-Podání, ale to je text
 * z února 2025, tedy z doby PŘED zavedením JMHZ (protokol v1.47 agendu JMHZ
 * vůbec nezná — nepadne v něm ani jednou). Stránka komunikačních kanálů je
 * novější a zřizuje pro JMHZ schránku vlastní. Novější a agendě bližší údaj
 * vyhrává, obecná schránka zůstává zapsaná jako doložená záloha.
 *
 * Schránka místně příslušné OSSZ/PSSZ/MSSZ je podle obou zdrojů také přípustná,
 * ale její ID závisí na firmě a nemáme ho odkud vzít — katalog ji proto nenabízí
 * a uživatel si ji v ručním režimu zadá sám.
 */
final readonly class JmhzIsdsRecipientCatalog
{
    /** Datová schránka zřízená výslovně pro JMHZ. */
    public const JMHZ_PRODUCTION_BOX_ID = 'iie254d';
    public const JMHZ_PRODUCTION_BOX_NAME =
        'JMHZ - Jednotné měsíční hlášení zaměstnavatele (Česká správa sociálního zabezpečení)';

    /** Obecná specializovaná schránka e-Podání ČSSZ. */
    public const GENERAL_PRODUCTION_BOX_ID = '5ffu6xk';
    public const GENERAL_PRODUCTION_BOX_NAME = 'e-podani ČSSZ';

    /** Testovací prostředí ČSSZ je napojené na testovací ISDS (czebox.cz). */
    public const TEST_BOX_ID = '9tsaf6s';
    public const TEST_BOX_NAME = 'e-podání TEST';

    /**
     * Adresát pro dané prostředí.
     *
     * Testovací prostředí VĚDOMĚ nemá vlastní JMHZ schránku: ČSSZ ji nezřídila
     * a vymyslet ji nelze, takže test jde do obecné testovací schránky. Kdyby se
     * `iie254d` použilo i v testu, mířilo by cvičné podání na ostrou schránku.
     */
    public static function forEnvironment(string $environment): PayrollIsdsRecipient
    {
        return match (self::normalize($environment)) {
            'production' => new PayrollIsdsRecipient(
                self::JMHZ_PRODUCTION_BOX_ID,
                self::JMHZ_PRODUCTION_BOX_NAME,
                'production',
                'Datová schránka zřízená ČSSZ výslovně pro JMHZ.',
            ),
            'test' => new PayrollIsdsRecipient(
                self::TEST_BOX_ID,
                self::TEST_BOX_NAME,
                'test',
                'Testovací schránka e-Podání ČSSZ; vlastní testovací schránku JMHZ nemá.',
            ),
            default => throw new JmhzTransportException(
                'jmhz_isds_environment_unknown',
                'Pro tohle prostředí není doložená datová schránka ČSSZ.',
            ),
        };
    }

    /**
     * Obecná schránka e-Podání ČSSZ jako doložená záloha pro produkci.
     *
     * Nabízí se jen tehdy, kdyby JMHZ schránka odmítala příjem; není to výchozí
     * cesta a sama se nepoužije.
     */
    public static function generalFallback(): PayrollIsdsRecipient
    {
        return new PayrollIsdsRecipient(
            self::GENERAL_PRODUCTION_BOX_ID,
            self::GENERAL_PRODUCTION_BOX_NAME,
            'production',
            'Obecná specializovaná schránka e-Podání ČSSZ (záloha, ne výchozí cesta).',
        );
    }

    /**
     * Je tohle některý z doložených adresátů ČSSZ?
     *
     * Používá se před odesláním: zpráva s mzdovými údaji nesmí odejít jinam než
     * do schránky, kterou máme podloženou zdrojem.
     */
    public static function isKnownRecipient(string $boxId): bool
    {
        return in_array(strtolower(trim($boxId)), [
            self::JMHZ_PRODUCTION_BOX_ID,
            self::GENERAL_PRODUCTION_BOX_ID,
            self::TEST_BOX_ID,
        ], true);
    }

    private static function normalize(string $environment): string
    {
        return strtolower(trim($environment));
    }
}
