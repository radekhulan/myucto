<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Service\Payroll\Absence\VacationCompensationReturn;

/**
 * Smí mzdový vstup složky nést zápornou částku?
 *
 * ── Co bylo špatně ──────────────────────────────────────────────────────────
 * Zápornou úkolovou mzdu nebo nadlimitní cestovní náhradu (oprava dřívějšího
 * zadání) šlo uložit i schválit. Odmítl ji až zákonný výpočet blokací
 * `negative_component_requires_revision`, tedy po zamknutí vstupů: běh se
 * rušil, vstup opravoval a běh zakládal znovu. Odpočet zálohy na cestovné
 * (složka mimo všechny základy) přitom záporný být smí a prošel.
 *
 * ── Pravidlo ────────────────────────────────────────────────────────────────
 * Jedno místo pro výpočet i uložení vstupu. Záporná částka vadí tam, kde
 * složka vstupuje do vyměřovacího základu pojistného nebo do základu daně
 * ({@see self::entersDomainBase()}): zápornou částkou by se snižoval základ
 * TOHOTO měsíce, ačkoli oprava patří do období, ke kterému se vztahuje.
 * Výpočet ({@see \MyInvoice\Service\Payroll\Run\PayrollRunStatutoryInputAssembler})
 * se ptá po jednotlivých základech, uložení vstupu na všechny najednou: složku,
 * kterou by výpočet zastavil v kterémkoli základu, odmítne už uložení.
 *
 * Jediný rozdíl je vrácená náhrada za dovolenou (§ 147 odst. 1 písm. e) ZP).
 * Výpočet ji pustí, jen když nestáhne úhrn základu vztahu pod nulu; ten se při
 * uložení jednoho vstupu ještě nezná, proto ji uložení pustí vždy a rozhodne
 * výpočet.
 */
final class PayrollNegativeAmountPolicy
{
    public const DOMAINS = ['social_insurance', 'health_insurance', 'income_tax'];

    /**
     * Vstupuje složka do vyměřovacího základu TÉHLE domény?
     *
     * Náhrada mzdy při DPN je osvobozená od daně (§ 6 odst. 9 písm. p) ZDP)
     * a tím pádem mimo vyměřovací základ sociálního i zdravotního pojistného
     * (§ 5 odst. 1 zák. 589/1992 Sb.). Do žádné ze tří domén tedy nevstupuje
     * a její storno záporné být smí.
     *
     * `manual_review` se tu bere jako „vstupuje": nevíme to jistě, a mlčet
     * u záporné částky, o které nevíme, kam patří, by bylo horší než falešný
     * poplach.
     *
     * @param array<string,mixed> $component snímek nebo řádek definice složky
     */
    public static function entersDomainBase(array $component, string $domain): bool
    {
        return match ($domain) {
            'social_insurance' => ($component['social_treatment'] ?? 'manual_review') !== 'excluded',
            'health_insurance' => ($component['health_treatment'] ?? 'manual_review') !== 'excluded',
            'income_tax' => !in_array(
                $component['tax_treatment'] ?? 'manual_review',
                ['exempt', 'withholding_candidate'],
                true,
            ),
            default => true,
        };
    }

    /**
     * Odmítne uložení vstupu této složky se zápornou částkou?
     *
     * @param array<string,mixed> $component snímek nebo řádek definice složky
     */
    public static function rejectsNegativeInput(array $component): bool
    {
        if (VacationCompensationReturn::mayBeNegative($component['code'] ?? null)) {
            return false;
        }
        foreach (self::DOMAINS as $domain) {
            if (self::entersDomainBase($component, $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $component snímek nebo řádek definice složky
     * @throws \InvalidArgumentException záporná částka u složky, která ji mít nesmí
     */
    public static function assertAllowed(array $component, int $amountMinor): void
    {
        if ($amountMinor >= 0 || !self::rejectsNegativeInput($component)) {
            return;
        }
        $code = (string) ($component['code'] ?? '');
        $name = (string) ($component['name'] ?? '');
        throw new \InvalidArgumentException(sprintf(
            'Složka %s nesmí mít zápornou částku: vstupuje do vyměřovacího základu pojistného '
            . 'nebo do základu daně a výpočet by mzdový běh zastavil. Opravujete-li dřívější '
            . 'zadání v tomto měsíci, upravte nebo zrušte původní vstup. Oprava mzdy za měsíc, '
            . 'jehož běh je už schválený, patří do opravné revize toho běhu. Záporná částka je '
            . 'přípustná jen u složek mimo základy (například odpočet zálohy na pracovní cestu '
            . 'nebo srážka).',
            $name !== '' && $code !== '' ? "{$name} ({$code})" : ($code !== '' ? $code : $name),
        ));
    }
}
