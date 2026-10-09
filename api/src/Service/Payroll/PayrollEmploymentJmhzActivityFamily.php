<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollEmploymentJmhzActivityFamily
{
    private const DPP_ACTIVITY_CODES = ['T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'ZA', 'ZB', 'ZC'];

    /**
     * Druhy činnosti formuláře `cinnostKS` (kontrola 343 ČSSZ: K, N, O, P, Q,
     * R, S). Společník, jednatel nebo člen orgánu se nevykazuje jen kódem S:
     * prokurista je P, člen kolektivního orgánu Q, likvidátor R. Přijatá
     * hlášení jiného mzdového systému nesou u takového vztahu P s kódem ELDP
     * P++ a ČSSZ je přijala. M (pěstoun) sem nepatří, má vlastní formulář.
     */
    public const CORPORATE_BODY_ACTIVITY_CODES = ['K', 'N', 'O', 'P', 'Q', 'R', 'S'];

    public static function isCorporateBodyActivity(mixed $activityCode): bool
    {
        return in_array($activityCode, self::CORPORATE_BODY_ACTIVITY_CODES, true);
    }

    /** Druh činnosti „T“ až „ZC“: dohoda o provedení práce (kontroly 245 a 325 ČSSZ). */
    public static function isAgreementToCompleteJobActivity(mixed $activityCode): bool
    {
        return in_array($activityCode, self::DPP_ACTIVITY_CODES, true);
    }

    /**
     * Bližší určení pracovněprávního vztahu (10502) „výkon trestu odnětí
     * svobody nebo zabezpečovací detence". U druhu činnosti 1 až 9 vede na
     * formulář `vezen` (datový scénář 4, kontrola 343 bod 4). Odsouzený
     * zařazený do práce je pro pojistné zaměstnanec (§ 5 odst. 1 písm. a)
     * bod 11 ZPSZ), počítá se proto jako pracovní poměr.
     */
    public const PRISONER_RELATIONSHIP_DETAIL = '2';

    /**
     * Druhy činnosti 11 až 14: náhrada od pojišťovny za škodu při plnění
     * pracovních úkolů (11), mezinárodní pronájem pracovní síly (12), jiný
     * příjem ze závislé činnosti vyplácený plátcem, u kterého se činnost
     * nevykonává (13), a neuvolněný člen zastupitelstva (14).
     *
     * Jde o příjmy ze závislé činnosti podle § 6 ZDP, které u plátce nezakládají
     * účast na pojištění: datové scénáře 5 a 6 (formuláře `jinyPrijem`
     * a `mezinarodniPronajemSily`) proto nemají žádný atribut pojištění, a
     * pravidla podání JMHZ 1.4.5, kap. 13 bod 4 u neuvolněného zastupitele
     * výslovně uvádějí, že pojistné se z odměny neodvádí.
     */
    public const OUTSIDE_INSURANCE_ACTIVITY_CODES = ['11', '12', '13', '14'];

    public static function isOutsideStatutoryInsurance(mixed $activityCode): bool
    {
        return in_array($activityCode, self::OUTSIDE_INSURANCE_ACTIVITY_CODES, true);
    }

    public static function appliesTo(string $relationType): bool
    {
        return in_array(
            $relationType,
            ['employment', 'small_scale_employment', 'dpc', 'dpp', 'partner_dependent', 'statutory_body'],
            true,
        );
    }

    /**
     * Druh činnosti pro ČSSZ u PRVNÍHO vztahu daného druhu u zaměstnavatele.
     *
     * Zakládaný zaměstnanec u firmy žádný jiný vztah nemá, takže „první
     * pracovní poměr" (1), „dohoda o pracovní činnosti" (A), „dohoda o
     * provedení práce" (T) i „člen statutárního orgánu" (S) jsou jednoznačné.
     * Dřív pole zůstalo prázdné a chybějící kód se poznal až na obrazovce
     * registrace nebo při sestavování měsíčního hlášení — tedy ve chvíli, kdy
     * už běží lhůta. Je to NÁVRH při založení, účetní ho může přepsat.
     *
     * @return array{0:?string,1:?string} [activity_code, relationship_detail_code]
     */
    public static function firstRelationDefaults(string $relationType): array
    {
        return match ($relationType) {
            'employment', 'small_scale_employment' => ['1', '1'],
            'dpc' => ['A', null],
            'dpp' => ['T', null],
            'partner_dependent', 'statutory_body' => ['S', '1'],
            default => [null, null],
        };
    }

    /**
     * Druh činnosti pro DALŠÍ vztah téhož druhu u zaměstnavatele.
     *
     * Kód nese pořadí souběžného vztahu: druhý pracovní poměr u téhož
     * zaměstnavatele je „2", druhá DPČ „B", druhá DPP „U". Dostane se první
     * kód řady, který žádný jiný živý vztah osoby nepoužívá — po skončení
     * prvního poměru tak nový zase dostane „1". Kód mimo řadu (vedle „1"
     * třeba „10") pořadí neovlivní. Je to NÁVRH, účetní ho může přepsat.
     *
     * @param list<string> $usedCodes kódy živých souběžných vztahů osoby
     * @return array{0:?string,1:?string} [activity_code, relationship_detail_code]
     */
    public static function nextRelationDefaults(string $relationType, array $usedCodes): array
    {
        [$first, $detail] = self::firstRelationDefaults($relationType);
        $sequence = match ($relationType) {
            'employment', 'small_scale_employment' => array_map(strval(...), range(1, 9)),
            'dpc' => range('A', 'J'),
            'dpp' => self::DPP_ACTIVITY_CODES,
            // Společník a člen orgánu pořadí v kódu nenesou.
            default => null,
        };
        if ($sequence === null) {
            return [$first, $detail];
        }
        foreach ($sequence as $code) {
            if (!in_array($code, $usedCodes, true)) {
                return [$code, $detail];
            }
        }

        return [null, null];
    }

    public static function matches(
        string $relationType,
        string $activityCode,
        ?string $relationshipDetailCode,
    ): bool {
        return match ($relationType) {
            'employment' => (preg_match('/^[1-9]$/D', $activityCode) === 1
                    && in_array($relationshipDetailCode, ['1', self::PRISONER_RELATIONSHIP_DETAIL], true))
                || (self::isOutsideStatutoryInsurance($activityCode) && $relationshipDetailCode === '1'),
            'small_scale_employment' => preg_match('/^[1-9]$/D', $activityCode) === 1
                && in_array($relationshipDetailCode, ['1', self::PRISONER_RELATIONSHIP_DETAIL], true),
            'dpc' => preg_match('/^[A-J]$/D', $activityCode) === 1
                && $relationshipDetailCode === null,
            'dpp' => in_array($activityCode, self::DPP_ACTIVITY_CODES, true)
                && $relationshipDetailCode === null,
            'partner_dependent', 'statutory_body' => self::isCorporateBodyActivity($activityCode)
                && $relationshipDetailCode === '1',
            default => false,
        };
    }
}
