<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final readonly class PayrollSheetMonth
{
    /** Měsíc nese údaje § 38j odst. 2 písm. f) bodů 2 a 3 zmrazené v revizi. */
    public const TAX_DETAIL_RECORDED = 'recorded';

    /**
     * Revize vznikla nad mapováním, které osvobozené částky ani základ srážkové
     * daně neevidovalo. Není to nula — je to neevidovaný údaj a doklad ho tak
     * musí i pojmenovat.
     */
    public const TAX_DETAIL_NOT_RECORDED = 'not_recorded';

    /** Měsíc nese měsíční daňové zvýhodnění podle § 38j odst. 2 písm. f) bodu 6. */
    public const CHILD_DETAIL_RECORDED = 'recorded';

    /**
     * Revize vznikla nad mapováním, které rozlišovalo jen UPLATNĚNOU slevu podle
     * § 35c. Nárok se z něj zpětně nedopočítá — uplatněná část mu je rovna jen
     * tehdy, když se celý nárok vešel do daně.
     */
    public const CHILD_DETAIL_NOT_RECORDED = 'not_recorded';

    /**
     * Sleva podle § 35ba je v měsíci UPLATNĚNÁ, tedy omezená výší zálohy podle
     * § 35d odst. 3 věty první. Jen tak dává bod 5 smysl jako celek.
     */
    public const CREDIT_DETAIL_APPLIED = 'applied';

    /**
     * Revize vznikla nad mapováním, které do dokladu tisklo NÁROKOVANOU slevu
     * podle § 35ba. U nízké mzdy je vyšší než ta poskytnutá; zpětně se rozdíl
     * z dokladu nedopočítá, protože nárok nad zálohu nemá kam jít.
     */
    public const CREDIT_DETAIL_CLAIMED = 'claimed';

    public function __construct(
        public int $month,
        public int $sourceRevisionCount,
        public int $grossMinorUnits,
        public int $cashIncomeMinorUnits,
        public int $nonCashIncomeMinorUnits,
        public int $socialAssessmentBaseMinorUnits,
        public int $employeeSocialMinorUnits,
        public int $employerSocialMinorUnits,
        public int $healthAssessmentBaseMinorUnits,
        public int $employeeHealthMinorUnits,
        public int $employerHealthMinorUnits,
        public int $healthMinimumTopUpMinorUnits,
        public int $advanceTaxBaseMinorUnits,
        public int $advanceTaxBeforeCreditsMinorUnits,
        /**
         * § 38j odst. 2 písm. f) bod 5 — „měsíční slevu na dani podle § 35ba
         * a zálohu sníženou o měsíční slevu na dani podle § 35ba".
         *
         * Je to UPLATNĚNÁ sleva, ne nárok. „Měsíční sleva na dani podle § 35ba"
         * je legální zkratka zavedená v § 35d odst. 2 a hned v odst. 3 větě
         * první omezená: plátce ji poskytne „maximálně do výše zálohy na daň
         * vypočtené podle § 38h odst. 2 a 3". Nárok nad tuhle hranici zákon
         * nepojmenovává a nikam ho nepřevádí — na rozdíl od § 35c, kde přebytek
         * nároku končí bonusem, a právě proto bod 6 vypisuje nárok i uplatnění
         * odděleně, kdežto bod 5 jen jedno číslo.
         *
         * Rozhoduje i druhá polovina bodu 5: záloha snížená o tuhle slevu se
         * musí rovnat sražené záloze. S nárokovanou částkou by u nízké mzdy
         * vyšla záporná a doklad by si protiřečil sám se sebou.
         */
        public int $nonRefundableCreditsMinorUnits,
        public int $childCreditMinorUnits,
        public int $advanceTaxMinorUnits,
        public int $taxBonusMinorUnits,
        public int $withholdingTaxMinorUnits,
        public int $otherDeductionsMinorUnits,
        public int $netPayableMinorUnits,
        /**
         * Doplatek ze zúčtování vyplacený v tomhle měsíci (§ 35d odst. 8).
         * Mzdový list ho vede odděleně — § 38j odst. 2 písm. h) žádá údaje
         * o provedeném ročním zúčtování, a do úhrnu mezd doplatek nepatří.
         */
        public int $annualSettlementMinorUnits = 0,
        /**
         * § 38j odst. 2 písm. f) bod 3 — základ pro výpočet DANĚ PODLE ZVLÁŠTNÍ
         * SAZBY. Bod 3 žádá základ obou způsobů výpočtu; zálohový základ sám
         * o sobě u srážkové daně nevykazuje nic.
         */
        public int $withholdingTaxBaseMinorUnits = 0,
        /**
         * § 38j odst. 2 písm. f) bod 2 — „částky osvobozené od daně z úhrnu
         * zúčtovaných mezd uvedeného v bodě 1". Je to PODMNOŽINA hrubého příjmu,
         * ne přičítaná položka: do čisté mzdy nevstupuje samostatně.
         */
        public int $taxExemptIncomeMinorUnits = 0,
        public string $taxDetailStatus = self::TAX_DETAIL_NOT_RECORDED,
        /**
         * § 38j odst. 2 písm. f) bod 6 — „měsíční daňové zvýhodnění, měsíční
         * slevu na dani podle § 35c, měsíční daňový bonus a zálohu sníženou
         * o měsíční slevu na dani podle § 35ba a 35c".
         *
         * Bod 6 vyjmenovává Čtyři údaje, ne tři. „Měsíční daňové zvýhodnění"
         * je NÁROK podle § 35c odst. 1; „měsíční sleva na dani podle § 35c"
         * je jen ta jeho část, která se vešla do daně (§ 35c odst. 2), a bonus
         * je část náležející podle § 35c odst. 3. Když nárok není pokrytý
         * daní a zároveň není nárok na bonus, nesejdou se — a právě ten rozdíl
         * doklad bez téhle položky zamlčel.
         */
        public int $childEntitlementMinorUnits = 0,
        public string $childDetailStatus = self::CHILD_DETAIL_NOT_RECORDED,
        public string $creditDetailStatus = self::CREDIT_DETAIL_CLAIMED,
    ) {
        if ($month < 1 || $month > 12 || $sourceRevisionCount <= 0) {
            throw new \InvalidArgumentException('Měsíční řádek mzdového listu nemá platné období.');
        }
        foreach ($this->amounts() as $field => $amount) {
            // Jediná položka, která smí být záporná, je čistá výplata. Měsíc
            // bez peněžního příjmu s doplatkem zdravotního pojištění do
            // minimálního vyměřovacího základu (§ 3 odst. 10 z. č. 592/1992
            // Sb.) skončí dluhem zaměstnance vůči zaměstnavateli a mzdový list
            // podle § 38j odst. 2 ZDP ho musí ukázat takový, jaký je. Příjmy,
            // základy, odvody, daň, bonus ani srážky záporné být nesmí — tam
            // by záporná částka znamenala poškozený podklad, ne skutečnost.
            $signed = $field === 'net_payable_minor_units';
            if ((!$signed && $amount < 0)
                || $amount > 1_000_000_000_000
                || $amount < -1_000_000_000_000
            ) {
                throw new \InvalidArgumentException('Měsíční částka mzdového listu není platná.');
            }
        }
        if (!in_array($taxDetailStatus, [
            self::TAX_DETAIL_RECORDED,
            self::TAX_DETAIL_NOT_RECORDED,
        ], true)) {
            throw new \InvalidArgumentException('Stav daňových údajů měsíce není platný.');
        }
        if (!in_array($childDetailStatus, [
            self::CHILD_DETAIL_RECORDED,
            self::CHILD_DETAIL_NOT_RECORDED,
        ], true)) {
            throw new \InvalidArgumentException(
                'Stav údajů o daňovém zvýhodnění měsíce není platný.',
            );
        }
        if ($childDetailStatus === self::CHILD_DETAIL_NOT_RECORDED
            && $childEntitlementMinorUnits !== 0
        ) {
            throw new \InvalidArgumentException(
                'Neevidované daňové zvýhodnění měsíce nesmí nést částku.',
            );
        }
        if ($childDetailStatus === self::CHILD_DETAIL_RECORDED
            && $childEntitlementMinorUnits < $childCreditMinorUnits + $taxBonusMinorUnits
        ) {
            // Sleva a bonus jsou ČÁSTI nároku (§ 35c odst. 2 a 3). Kdyby je nárok
            // nepokryl, doklad by tvrdil, že se uplatnilo víc, než na co byl nárok.
            throw new \InvalidArgumentException(
                'Uplatněná sleva a bonus převyšují měsíční daňové zvýhodnění.',
            );
        }
        if (!in_array($creditDetailStatus, [
            self::CREDIT_DETAIL_APPLIED,
            self::CREDIT_DETAIL_CLAIMED,
        ], true)) {
            throw new \InvalidArgumentException(
                'Stav údajů o slevě podle § 35ba měsíce není platný.',
            );
        }
        if ($creditDetailStatus === self::CREDIT_DETAIL_APPLIED
            && $nonRefundableCreditsMinorUnits + $childCreditMinorUnits
                > $advanceTaxBeforeCreditsMinorUnits
        ) {
            // § 35d odst. 3: sleva podle § 35ba se poskytne do výše zálohy
            // a sleva podle § 35c do výše zálohy o ni snížené. Součet obou
            // proto zálohu před slevami nikdy nepřevýší; kdyby ano, „záloha
            // snížená o měsíční slevu" podle bodů 5 a 6 by byla záporná.
            throw new \InvalidArgumentException(
                'Uplatněné slevy převyšují zálohu na daň před slevami.',
            );
        }
        if ($taxDetailStatus === self::TAX_DETAIL_NOT_RECORDED
            && ($taxExemptIncomeMinorUnits !== 0 || $withholdingTaxBaseMinorUnits !== 0)
        ) {
            throw new \InvalidArgumentException(
                'Neevidované daňové údaje měsíce nesmí nést částku.',
            );
        }
        if ($taxExemptIncomeMinorUnits > $grossMinorUnits) {
            throw new \InvalidArgumentException(
                'Osvobozená částka převyšuje úhrn zúčtovaných mezd.',
            );
        }
        if ($cashIncomeMinorUnits + $nonCashIncomeMinorUnits !== $grossMinorUnits) {
            throw new \InvalidArgumentException('Peněžní a nepeněžní příjem nesouhlasí s hrubým příjmem.');
        }
        $expectedNet = $cashIncomeMinorUnits
            - $employeeSocialMinorUnits
            - $employeeHealthMinorUnits
            - $healthMinimumTopUpMinorUnits
            - $advanceTaxMinorUnits
            - $withholdingTaxMinorUnits
            - $otherDeductionsMinorUnits
            + $taxBonusMinorUnits
            + $annualSettlementMinorUnits;
        if ($expectedNet !== $netPayableMinorUnits) {
            throw new \InvalidArgumentException(
                'Čistá výplata nesouhlasí s příjmem, odvody, daní, bonusem a srážkami.',
            );
        }
    }

    /** @return array<string,int> */
    public function amounts(): array
    {
        return [
            'gross_minor_units' => $this->grossMinorUnits,
            'cash_income_minor_units' => $this->cashIncomeMinorUnits,
            'non_cash_income_minor_units' => $this->nonCashIncomeMinorUnits,
            'social_assessment_base_minor_units' => $this->socialAssessmentBaseMinorUnits,
            'employee_social_minor_units' => $this->employeeSocialMinorUnits,
            'employer_social_minor_units' => $this->employerSocialMinorUnits,
            'health_assessment_base_minor_units' => $this->healthAssessmentBaseMinorUnits,
            'employee_health_minor_units' => $this->employeeHealthMinorUnits,
            'employer_health_minor_units' => $this->employerHealthMinorUnits,
            'health_minimum_top_up_minor_units' => $this->healthMinimumTopUpMinorUnits,
            'tax_exempt_income_minor_units' => $this->taxExemptIncomeMinorUnits,
            'advance_tax_base_minor_units' => $this->advanceTaxBaseMinorUnits,
            'withholding_tax_base_minor_units' => $this->withholdingTaxBaseMinorUnits,
            'advance_tax_before_credits_minor_units' => $this->advanceTaxBeforeCreditsMinorUnits,
            'non_refundable_credits_minor_units' => $this->nonRefundableCreditsMinorUnits,
            'child_entitlement_minor_units' => $this->childEntitlementMinorUnits,
            'child_credit_minor_units' => $this->childCreditMinorUnits,
            'advance_tax_minor_units' => $this->advanceTaxMinorUnits,
            'tax_bonus_minor_units' => $this->taxBonusMinorUnits,
            'withholding_tax_minor_units' => $this->withholdingTaxMinorUnits,
            'other_deductions_minor_units' => $this->otherDeductionsMinorUnits,
            'annual_settlement_minor_units' => $this->annualSettlementMinorUnits,
            'net_payable_minor_units' => $this->netPayableMinorUnits,
        ];
    }

    public function taxDetailRecorded(): bool
    {
        return $this->taxDetailStatus === self::TAX_DETAIL_RECORDED;
    }

    public function childDetailRecorded(): bool
    {
        return $this->childDetailStatus === self::CHILD_DETAIL_RECORDED;
    }

    public function creditDetailApplied(): bool
    {
        return $this->creditDetailStatus === self::CREDIT_DETAIL_APPLIED;
    }

    /** @return array<string,int|string> */
    public function toTemplateData(): array
    {
        return [
            'month' => $this->month,
            'source_revision_count' => $this->sourceRevisionCount,
            ...$this->amounts(),
            'tax_detail_status' => $this->taxDetailStatus,
            'child_detail_status' => $this->childDetailStatus,
            'credit_detail_status' => $this->creditDetailStatus,
        ];
    }
}
