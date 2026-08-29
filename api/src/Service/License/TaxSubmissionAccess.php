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
     * chybějící částky na daňovém bonusu, § 35d odst. 5 a 9) a stejně tak
     * `dpzvd6` ani `dpsvd2` (roční vyúčtování zálohové a srážkové daně,
     * § 38j odst. 4 a § 38d): všechny čtyři se staví výhradně ze zmrazených
     * výsledků mzdového běhu a celé `/api/payroll` je za licencí. Bezplatný
     * zákazník nemá data, ze kterých by je bylo možné sestavit, takže uvolnit
     * sem jejich XML by nikomu nic neodemklo — jen rozostřilo dělicí čáru mezi
     * bezplatnou a placenou částí.
     *
     * `dpshl1` (oznámení o příjmech plynoucích do zahraničí, § 38da) a `dpszd1`
     * (hlášení o srážce zajištění daně, § 38e) tu nejsou z jiného důvodu než
     * předchozí čtyři: z mezd nevznikají a jejich věcnou část zadává uživatel,
     * takže by je bezplatný zákazník sestavit uměl. Jsou to ale podání pro
     * platby do zahraničí, tedy agenda, kterou drží placená část — a výchozí
     * odpověď zůstává „placené", dokud se nerozhodne jinak.
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
