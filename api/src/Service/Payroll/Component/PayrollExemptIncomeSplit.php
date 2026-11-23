<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

/**
 * Rozpad jedné zmrazené mzdové složky na zdaněnou a nezdaněnou část.
 *
 * Existuje proto, že tenhle rozpad čtou DVA doklady o téže mzdě — výplatní
 * páska (§ 142 odst. 6 zákoníku práce, „údaje o jednotlivých složkách mzdy")
 * a mzdový list (§ 38j odst. 2 písm. f) bod 2 ZDP, „částky osvobozené od daně
 * z úhrnu zúčtovaných mezd"). Kdyby si každý doklad počítal svoje, můžou se
 * rozejít, a dva doklady o téže mzdě, které se čtou různě, jsou horší než jeden
 * neúplný.
 *
 * Nic se tu nedopočítává. Čte se ZMRAZENÝ vstup schválené revize: daňové
 * zacházení složky, podklad osvobození a u benefitního koše zmrazený rozpad
 * (`benefit_exempt_minor`). Pozdější přepočet koše by dal jiné dělení, protože
 * koš čerpají všechny složky téhož bodu v pořadí schválení.
 */
final readonly class PayrollExemptIncomeSplit
{
    private function __construct(
        public ?PayrollExemptionBasis $basis,
        /**
         * Část částky vstupu, která se nezdaňuje. U složky mimo koš je to celá
         * částka (i záporná u opravy minulého období), u koše zmrazený podíl.
         */
        public int $exemptMinorUnits,
    ) {}

    /**
     * Část, kterou mzdový list vykazuje jako „částku osvobozenou od daně".
     *
     * Plnění mimo předmět daně (§ 6 odst. 7 ZDP) mezi ně nepatří — na pásce se
     * ale uvést musí, jinak by z ní nešlo přečíst, proč se z něj nic nesrazilo.
     */
    public function reportedExemptMinorUnits(): int
    {
        return $this->basis?->isReportedAsExemptIncome() === true
            ? $this->exemptMinorUnits
            : 0;
    }

    public function notSubjectToTaxMinorUnits(): int
    {
        return $this->basis === PayrollExemptionBasis::NotSubjectToTax
            ? $this->exemptMinorUnits
            : 0;
    }

    /**
     * @param array<string,mixed> $frozenInput zmrazený mzdový vstup revize
     */
    public static function fromFrozenInput(
        array $frozenInput,
        int $sourceAmountMinorUnits,
        int $inputId,
    ): self {
        $component = $frozenInput['component'] ?? null;
        if (!is_array($component) || array_is_list($component)) {
            throw new \DomainException("Vstup {$inputId} nemá zmrazenou složku.");
        }
        $treatment = $component['tax_treatment'] ?? null;
        if (!in_array($treatment, ['included', 'withholding_candidate', 'exempt'], true)) {
            // Nezaklasifikovaná složka nesmí projít jako nula. Do schválené
            // revize se dostat neměla — výpočet daně na ni vrací
            // `income-component-tax-treatment-unverified` a osoba skončí
            // v ručním posouzení — ale doklad to tvrdit nebude.
            throw new \DomainException(
                "Daňové zacházení složky vstupu {$inputId} není uzavřené.",
            );
        }
        if ($treatment !== 'exempt') {
            return new self(null, 0);
        }

        $basis = PayrollExemptionBasis::tryFrom(
            is_string($component['exemption_basis'] ?? null)
                ? $component['exemption_basis']
                : '',
        );
        if ($basis === null) {
            // Do schválené revize se takový vstup dostat neměl — sestavovač
            // zákonných vstupů i výpočet daně ho shodí do ručního posouzení.
            // Vykázat ho jako osvobozený příjem by znamenalo doložit branou
            // neprošlé tvrzení.
            throw new \DomainException(
                "Podklad osvobození složky vstupu {$inputId} není uveden.",
            );
        }
        return new self($basis, self::frozenExemptPart(
            $frozenInput,
            $sourceAmountMinorUnits,
            $inputId,
        ));
    }

    /**
     * Osvobozená část částky vstupu osvobozené složky: u vstupu v koši
     * osvobození zmrazený podíl `benefit_exempt_minor`, jinak celá částka.
     *
     * Zdaněná nadlimitní část koše mezi osvobozené příjmy nepatří. Čtou to
     * doklady o mzdě i příprava měsíčního hlášení (10289 „úhrn osvobozených
     * příjmů“), proto je rozpad tady a ne u každého čtenáře zvlášť.
     *
     * @param array<string,mixed> $frozenInput zmrazený mzdový vstup revize
     */
    public static function frozenExemptPart(
        array $frozenInput,
        int $sourceAmountMinorUnits,
        int $inputId,
    ): int {
        if (($frozenInput['benefit_basket'] ?? null) === null) {
            return $sourceAmountMinorUnits;
        }

        $exempt = self::nonNegativeInt($frozenInput, 'benefit_exempt_minor', $inputId);
        $taxable = self::nonNegativeInt($frozenInput, 'benefit_taxable_minor', $inputId);
        if ($exempt + $taxable !== $sourceAmountMinorUnits) {
            throw new \DomainException(
                "Zmrazený rozpad koše osvobození vstupu {$inputId} nedává částku vstupu.",
            );
        }

        return $exempt;
    }

    /** @param array<string,mixed> $row */
    private static function nonNegativeInt(array $row, string $key, int $inputId): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new \DomainException(
                "Zmrazený rozpad koše osvobození vstupu {$inputId} nemá pole {$key}.",
            );
        }

        return $value;
    }
}
