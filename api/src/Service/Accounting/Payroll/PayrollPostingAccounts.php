<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Payroll;

use MyInvoice\Service\Payroll\PayrollAccountingDefaults;

/**
 * Kontace mzdové rekapitulace — jaké ÚČTY se použijí, ne jaké částky.
 *
 * Do teď byly účty natvrdo v {@see PayrollCalculator::lines()} (`524`, `336`, `342`)
 * a v {@see PayrollCalculator::accounts()} (`521/331`, `522/366`). Firma, která má
 * osnovu rozanalytikovanou po účetní (`521.100`, `336.100`, …), se pak s deníkem
 * rozcházela na každém řádku, přestože částky seděly na korunu.
 *
 * Klíče jsou schválně SHODNÉ s {@see PayrollAccountingDefaults::ACCOUNTS} (novější
 * engine přes `payroll_employer_settings`) — obě větve tak čtou tutéž konfiguraci
 * a nemůžou se rozejít v tom, co je „účet pro zálohu na daň".
 *
 * ── Proč jsou sociální a zdravotní zvlášť ───────────────────────────────────
 * Syntetika 336 pokrývá OBĚ instituce. Kdo si ji rozdělí na 336.100 (OSSZ) a
 * 336.200 (ZP), potřebuje, aby se rozdělil i zápis — jinak na analytice visí
 * součet a saldo proti hromadnému příkazu (dvě platby, dva příjemci) nesedí.
 * Rozpad {@see PayrollCalculator::compute()} obě složky zná, jen je dosud slil
 * do jednoho řádku.
 */
final class PayrollPostingAccounts
{
    public const KEY_EMPLOYMENT_EXPENSE = 'employment_gross_debit';
    public const KEY_EMPLOYMENT_PAYABLE = 'employment_gross_credit';
    public const KEY_PARTNER_EXPENSE    = 'partner_gross_debit';
    public const KEY_PARTNER_PAYABLE    = 'partner_gross_credit';
    public const KEY_EMPLOYER_INSURANCE = 'employer_insurance_debit';
    public const KEY_SOCIAL_PAYABLE     = 'social_insurance_credit';
    public const KEY_HEALTH_PAYABLE     = 'health_insurance_credit';
    public const KEY_INCOME_TAX_PAYABLE = 'income_tax_credit';
    /**
     * Srážková daň (§ 36 odst. 2 písm. p) a § 6 odst. 4 ZDP) — vlastní účet,
     * ne táž 342 jako záloha. Odvádí se jinou platbou, v jiném termínu a
     * vykazuje jiným hlášením, takže na společném účtu nejde saldo přiřadit
     * k jedné z obou daní. Viz {@see PayrollAccountingDefaults::ACCOUNTS}.
     */
    public const KEY_WITHHOLDING_TAX_PAYABLE = 'withholding_tax_credit';

    /** @var list<string> Klíče v pořadí, ve kterém se konfigurace čte i zapisuje. */
    public const KEYS = [
        self::KEY_EMPLOYMENT_EXPENSE,
        self::KEY_EMPLOYMENT_PAYABLE,
        self::KEY_PARTNER_EXPENSE,
        self::KEY_PARTNER_PAYABLE,
        self::KEY_EMPLOYER_INSURANCE,
        self::KEY_SOCIAL_PAYABLE,
        self::KEY_HEALTH_PAYABLE,
        self::KEY_INCOME_TAX_PAYABLE,
        self::KEY_WITHHOLDING_TAX_PAYABLE,
    ];

    private function __construct(
        public readonly string $employmentExpense,
        public readonly string $employmentPayable,
        public readonly string $partnerExpense,
        public readonly string $partnerPayable,
        public readonly string $employerInsurance,
        public readonly string $socialPayable,
        public readonly string $healthPayable,
        public readonly string $incomeTaxPayable,
        public readonly string $withholdingTaxPayable,
    ) {}

    /**
     * Syntetiky dle směrné účtové osnovy — chování, jaké kontace měla, než šla
     * konfigurovat. Bere se z {@see PayrollAccountingDefaults}, aby existovala
     * jediná definice výchozích účtů pro obě větve.
     */
    public static function defaults(): self
    {
        return self::fromMap(PayrollAccountingDefaults::codes());
    }

    /**
     * @param array<string,mixed> $codes klíče {@see self::KEYS}; chybějící se doplní
     *        ze směrné osnovy, aby částečná konfigurace nezhavarovala na chybějícím účtu
     */
    public static function fromMap(array $codes): self
    {
        $fallback = PayrollAccountingDefaults::codes();
        $pick = static function (string $key) use ($codes, $fallback): string {
            $value = trim((string) ($codes[$key] ?? ''));
            return $value !== '' ? $value : (string) $fallback[$key];
        };

        return new self(
            $pick(self::KEY_EMPLOYMENT_EXPENSE),
            $pick(self::KEY_EMPLOYMENT_PAYABLE),
            $pick(self::KEY_PARTNER_EXPENSE),
            $pick(self::KEY_PARTNER_PAYABLE),
            $pick(self::KEY_EMPLOYER_INSURANCE),
            $pick(self::KEY_SOCIAL_PAYABLE),
            $pick(self::KEY_HEALTH_PAYABLE),
            $pick(self::KEY_INCOME_TAX_PAYABLE),
            $pick(self::KEY_WITHHOLDING_TAX_PAYABLE),
        );
    }

    /** @return array<string,string> */
    public function toMap(): array
    {
        return [
            self::KEY_EMPLOYMENT_EXPENSE => $this->employmentExpense,
            self::KEY_EMPLOYMENT_PAYABLE => $this->employmentPayable,
            self::KEY_PARTNER_EXPENSE    => $this->partnerExpense,
            self::KEY_PARTNER_PAYABLE    => $this->partnerPayable,
            self::KEY_EMPLOYER_INSURANCE => $this->employerInsurance,
            self::KEY_SOCIAL_PAYABLE     => $this->socialPayable,
            self::KEY_HEALTH_PAYABLE     => $this->healthPayable,
            self::KEY_INCOME_TAX_PAYABLE => $this->incomeTaxPayable,
            self::KEY_WITHHOLDING_TAX_PAYABLE => $this->withholdingTaxPayable,
        ];
    }

    /**
     * Náklad a závazek vůči poplatníkovi dle typu — konfigurovatelná obdoba
     * {@see PayrollCalculator::accounts()}.
     *
     * @return array{expense:string,payable:string}
     */
    public function forType(string $taxpayerType): array
    {
        return $taxpayerType === PayrollCalculator::TYPE_MANAGING_PARTNER
            ? ['expense' => $this->partnerExpense, 'payable' => $this->partnerPayable]
            : ['expense' => $this->employmentExpense, 'payable' => $this->employmentPayable];
    }

    /**
     * Účtuje se pojistné obou institucí na TÝŽ účet? Pak nemá smysl zápis dělit —
     * dva řádky se stejným účtem a stranou by v deníku jen přibyly.
     */
    public function insuranceIsPooled(): bool
    {
        return $this->socialPayable === $this->healthPayable;
    }

    /**
     * Účtuje se zálohová i srážková daň na TÝŽ účet? To je stav VŠECH firem
     * založených před Ú-13 — rozpad je výchozí jen pro nově zakládané.
     */
    public function taxIsPooled(): bool
    {
        return $this->incomeTaxPayable === $this->withholdingTaxPayable;
    }
}
