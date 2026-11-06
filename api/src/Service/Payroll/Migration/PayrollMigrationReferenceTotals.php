<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Service\Migration\Pohoda\PohodaXml;

/**
 * Úhrny jedné zpracované mzdy původního systému: pracovní vztah × měsíc.
 *
 * Je to PŘEVZATÁ strana kontrolní sestavy „naše přepočtená mzda vs. mzda převzatá
 * z PAMICA" ({@see \MyInvoice\Service\Payroll\Report\PayrollMigrationReconciliationBuilder}).
 * Ukládá se při převodu do `payroll_migration_reference_totals`, aby sestava fungovala
 * i bez původního exportu — účetní se k ní vrací měsíce po převodu, kdy už soubor
 * nikdo nemá po ruce.
 *
 * Vše v haléřích (celá čísla). Float se na peníze nepoužívá ani v mezikroku:
 * `PohodaXml::num()` vrací float z XML, převod na haléře dělá {@see self::minor()}
 * jediným zaokrouhlením.
 */
final readonly class PayrollMigrationReferenceTotals
{
    /**
     * Mapování polí `MZ` v exportu `91_mzdy.xml`.
     *
     * POZOR na rozdělení zaměstnanec / zaměstnavatel: `KcSoc` a `KcZdr` je pojistné
     * SRAŽENÉ ZAMĚSTNANCI, `KcSocL` a `KcZdrL` je pojistné ZAMĚSTNAVATELE. Sloupce se
     * jmenují skoro stejně a záměna by v sestavě vypadala jako obrovská odchylka, tak
     * je to ověřené na sazbách nad reálným exportem: u základu 43 706 Kč vyšlo
     * `KcSoc` = 3 104 (7,1 %), `KcSocL` = 10 839,09 (24,8 %), `KcZdr` = 1 967 (4,5 %)
     * a `KcZdrL` = 3 934 (9 %). Sedí to i s komentářem v `PohodaPayrollPeople::read()`.
     */
    private const FIELDS = [
        'gross' => 'KcHrubaM',
        'net' => 'KcCistaM',
        'social_base' => 'KcSocZak',
        'health_base' => 'KcZdrZak',
        'employee_social' => 'KcSoc',
        'employee_health' => 'KcZdr',
        'employer_social' => 'KcSocL',
        'employer_health' => 'KcZdrL',
        'advance_tax' => 'KcZalDan',
        'withholding_tax' => 'KcSraDan',
        'tax_bonus' => 'KcDanBon',
    ];

    public function __construct(
        public string $period,
        public string $externalPersonRef,
        public string $externalRelationshipRef,
        public ?int $employeeId,
        public ?int $employmentId,
        public int $grossMinor,
        public int $netMinor,
        public int $socialBaseMinor,
        public int $healthBaseMinor,
        public int $employeeSocialMinor,
        public int $employeeHealthMinor,
        public int $employerSocialMinor,
        public int $employerHealthMinor,
        public int $advanceTaxMinor,
        public int $withholdingTaxMinor,
        public int $taxBonusMinor,
        /**
         * Doby, druh vztahu a platba. Nepovinné, protože kontrolní sestava je
         * nepotřebuje — bez nich ale nevznikne ELDP za rok přechodu ani zpětná
         * evidence plateb ({@see PayrollMigrationTakeoverFacts}).
         */
        public PayrollMigrationTakeoverFacts $facts = new PayrollMigrationTakeoverFacts(),
    ) {
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new \InvalidArgumentException('Mzdové období musí být ve tvaru YYYY-MM.');
        }
        if ($externalPersonRef === '' || $externalRelationshipRef === '') {
            throw new \InvalidArgumentException(
                'Převzatá mzda musí nést identifikaci osoby i pracovního vztahu z původního systému.',
            );
        }
        if (($employeeId !== null && $employeeId <= 0) || ($employmentId !== null && $employmentId <= 0)) {
            throw new \InvalidArgumentException('Napárovaná osoba i vztah musí mít kladné id.');
        }
    }

    /**
     * Jeden záznam `MZ` z `91_mzdy.xml`. `$employeeId` a `$employmentId` doplňuje převod
     * z vlastní párovací mapy; nechá je `null`, pokud vztah do MyÚčta nepřevedl.
     *
     * Doby a platba se čtou z TÉHOŽ záznamu `MZ`, takže volající nemusí nic
     * měnit, aby se naplnily. Druh vztahu a druh činnosti z `MZ` odvodit nejdou
     * (`RelDruhM` je vlastní číselník PAMICA, ne kód ČSSZ) — kdo zná napárovaný
     * pracovní vztah, předá je zde, jinak zůstanou nevyplněné.
     *
     * @param array<string,mixed> $mz
     */
    public static function fromPohodaMz(
        array $mz,
        int $year,
        ?int $employeeId = null,
        ?int $employmentId = null,
        ?string $relationType = null,
        ?string $activityCode = null,
    ): self {
        $month = (int) PohodaXml::text($mz, 'RelMes');
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Mzda z původního systému nemá platný měsíc.');
        }
        $minor = static fn (string $key): int => self::minor(PohodaXml::num($mz, self::FIELDS[$key]));
        // `KcSocZak` vyplňuje PAMICA i v měsíci bez účasti na pojištění (DPP pod rozhodným
        // příjmem, jednatel bez odměny). Vyměřovacím základem (JMHZ 10477) ale není; jako
        // příjem včetně nepojištěné činnosti (10476) ho nese PayrollMigrationTakeoverFacts.
        $insured = PohodaXml::text($mz, 'JeSocPP') === '' || PohodaXml::num($mz, 'JeSocPP') > 0.0;

        return new self(
            sprintf('%04d-%02d', $year, $month),
            PohodaXml::text($mz, 'RefZAM'),
            PohodaXml::text($mz, 'RefPomer'),
            $employeeId,
            $employmentId,
            $minor('gross'),
            self::minor(self::pohodaNet($mz)),
            $insured ? $minor('social_base') : 0,
            $minor('health_base'),
            $minor('employee_social'),
            $minor('employee_health'),
            $minor('employer_social'),
            $minor('employer_health'),
            $minor('advance_tax'),
            $minor('withholding_tax'),
            $minor('tax_bonus'),
            PayrollMigrationTakeoverFacts::fromPohodaMz($mz, $relationType, $activityCode),
        );
    }

    /**
     * Čistá mzda záznamu `MZ` tak, jak ji vykazuje měsíční hlášení (JMHZ 10344): `KcCistaM`
     * a náhrada mzdy za dočasnou pracovní neschopnost (`KcNahrNem`), kterou PAMICA do
     * `KcCistaM` nezapočítává, ale vyplácí ji (`KcVyuct`) a hlásí v čisté mzdě.
     *
     * @param array<string,mixed> $mz
     */
    public static function pohodaNet(array $mz): float
    {
        return PohodaXml::num($mz, self::FIELDS['net']) + PohodaXml::num($mz, 'KcNahrNem');
    }

    /**
     * Úhrny měsíce z částek v korunách, klíčovaných metrikami sestavy
     * ({@see self::metrics()}: `gross`, `net`, `social_base`, …). Pro převody, které
     * zdrojová pole samy přeloží na metriky (PREMIER); převod na haléře dělá jediné
     * zaokrouhlení {@see self::minor()}.
     *
     * @param array<string,float|int> $amounts metrika => Kč; každá metrika je povinná
     */
    public static function fromAmounts(
        string $period,
        string $externalPersonRef,
        string $externalRelationshipRef,
        ?int $employeeId,
        ?int $employmentId,
        array $amounts,
        PayrollMigrationTakeoverFacts $facts = new PayrollMigrationTakeoverFacts(),
    ): self {
        $minor = static function (string $key) use ($amounts): int {
            if (!array_key_exists($key, $amounts)) {
                throw new \InvalidArgumentException("Převzatá mzda nemá úhrn {$key}.");
            }
            return self::minor((float) $amounts[$key]);
        };

        return new self(
            $period,
            $externalPersonRef,
            $externalRelationshipRef,
            $employeeId,
            $employmentId,
            $minor('gross'),
            $minor('net'),
            $minor('social_base'),
            $minor('health_base'),
            $minor('employee_social'),
            $minor('employee_health'),
            $minor('employer_social'),
            $minor('employer_health'),
            $minor('advance_tax'),
            $minor('withholding_tax'),
            $minor('tax_bonus'),
            $facts,
        );
    }

    /** @return array<string,int> metrika => haléře, v pořadí sloupců sestavy */
    public function metrics(): array
    {
        return [
            'gross' => $this->grossMinor,
            'net' => $this->netMinor,
            'social_base' => $this->socialBaseMinor,
            'health_base' => $this->healthBaseMinor,
            'employee_social' => $this->employeeSocialMinor,
            'employee_health' => $this->employeeHealthMinor,
            'employer_social' => $this->employerSocialMinor,
            'employer_health' => $this->employerHealthMinor,
            'advance_tax' => $this->advanceTaxMinor,
            'withholding_tax' => $this->withholdingTaxMinor,
            'tax_bonus' => $this->taxBonusMinor,
        ];
    }

    private static function minor(float $amount): int
    {
        return (int) round($amount * 100.0);
    }
}
