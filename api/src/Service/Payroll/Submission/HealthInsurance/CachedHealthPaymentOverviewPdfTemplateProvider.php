<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

use MyInvoice\Bootstrap;

/**
 * Oficiální tiskopis VZP „Přehled o platbě pojistného zaměstnavatele".
 *
 * Formulář je připnutý v repozitáři vedle XSD schémat, ne stahovaný z webu.
 * Dřív se tahal z `vzp.cz` a ukládal do cache; znělo to jako „vždycky aktuální
 * verze", ve skutečnosti to znamenalo tři věci navíc:
 *
 *  * podání i testy závisely na dostupnosti cizího webu — na CI kvůli tomu
 *    padaly čtyři testy na `zp_vzp_pdf_template_changed`, přestože ověřují
 *    stavový automat podání, ne bajty tiskopisu;
 *  * první běh po nasazení chodil ven a mohl selhat v nejhorší chvíli;
 *  * otisk stejně musel být připnutý v kódu, takže „aktuálnost" byla zdánlivá:
 *    jakmile VZP formulář změnila, aplikace ho odmítla tak jako tak.
 *
 * Nová verze formuláře je proto vědomá změna repozitáře: nahradit soubor,
 * přepsat {@see VZP_SHA256} a projít testy. Přesně jako u připnutých XSD, kde
 * je nesoulad otisku záměrná brzda, ne chyba.
 */
final class CachedHealthPaymentOverviewPdfTemplateProvider implements HealthPaymentOverviewPdfTemplateProvider
{
    /** Původ souboru. Zůstává kvůli doložitelnosti, ke stažení se nepoužívá. */
    public const VZP_URL = 'https://www.vzp.cz/formulare/prehled-o-platbe-pojistneho-zamestnavatele.pdf';

    public const VZP_SHA256 = 'c742e17ff44a79236638e5860a13ffff335805fa06a24890c5235b2c1ef322e3';

    private const RELATIVE_PATH = 'api/xsd/vzp/prehled-o-platbe-pojistneho-zamestnavatele.pdf';

    public function vzpPaymentOverview(): HealthPaymentOverviewPdfTemplate
    {
        $path = Bootstrap::rootDir() . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::RELATIVE_PATH);
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($bytes) || $bytes === '') {
            throw new HealthNotificationException(
                'zp_vzp_pdf_template_unavailable',
                'Oficiální formulář VZP chybí v instalaci (' . self::RELATIVE_PATH . ').',
            );
        }
        if (!hash_equals(self::VZP_SHA256, hash('sha256', $bytes))) {
            throw new HealthNotificationException(
                'zp_vzp_pdf_template_changed',
                'Oficiální formulář VZP neodpovídá připnutému otisku. '
                . 'Použijte ověřenou verzi souboru, nebo otisk aktualizujte spolu s ním.',
            );
        }

        return new HealthPaymentOverviewPdfTemplate($bytes, self::VZP_URL, self::VZP_SHA256);
    }
}
