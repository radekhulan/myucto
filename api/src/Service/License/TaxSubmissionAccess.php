<?php

declare(strict_types=1);

namespace MyInvoice\Service\License;

/**
 * Které daňové výkazy patří k bezplatné části a které za licenci.
 *
 * ⚠️ Bezplatná část výslovně zahrnuje přiznání k DPH i kontrolní hlášení —
 * je to tak napsané v {@see CommercialFeatureAccess}, ukazuje to menu a slibuje
 * to prodejní web („Výkazy DPH a KH v XML pro EPO"). Archiv podání byl přesto
 * zamčený celý, takže zákazník bez licence si výkaz vygeneroval, aplikace ho
 * poslala do archivu pro stažení XML — a tam ho vyhodila na aktivační
 * obrazovku. Výkaz tedy fakticky nešlo dostat ven.
 *
 * Dělicí čára není „výkaz vs. archiv", ale **XML vs. odeslání**: soubor si
 * zákazník stáhne a podá sám na portálu, kdežto přímé podání do EPO včetně
 * certifikátů a potvrzenek je služba, kterou provozujeme my, a ta je placená.
 * Přesně tak je to i na ceníku.
 */
final class TaxSubmissionAccess
{
    /**
     * Kódy výkazů, které jsou součástí bezplatné části.
     *
     * `ossei1` (OSS) tu schválně NENÍ: celý modul OSS je za licencí
     * ({@see CommercialFeatureAccess} má `/api/reports/oss`), takže jeho podání
     * by v bezplatné části nemělo co dělat. Nový výkaz se sem musí přidat
     * vědomě — výchozí odpověď je „placené".
     *
     * Ze stejného důvodu tu nejsou `dpzmb1` ani `dpzdb1` (žádosti o poukázání
     * chybějící částky na daňovém bonusu, § 35d odst. 5 a 9): staví se výhradně
     * ze zmrazených výsledků mzdového běhu a celé `/api/payroll` je za licencí.
     * Bezplatný zákazník nemá data, ze kterých by je bylo možné sestavit, takže
     * uvolnit sem jejich XML by nikomu nic neodemklo — jen rozostřilo dělicí
     * čáru mezi bezplatnou a placenou částí.
     *
     * @var list<string>
     */
    private const FREE_FORM_CODES = ['dphdp3', 'dphkh1', 'dphshv'];

    public static function isFreeForm(?string $formCode): bool
    {
        return $formCode !== null
            && in_array(strtolower(trim($formCode)), self::FREE_FORM_CODES, true);
    }

    /** @return list<string> */
    public static function freeFormCodes(): array
    {
        return self::FREE_FORM_CODES;
    }
}
