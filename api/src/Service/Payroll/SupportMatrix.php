<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetYearCoverage;

final class SupportMatrix
{
    public const VERSION = '2026-08-27-v9';

    /**
     * Mzdový rok je podporovaný jen tehdy, když ho pokrývají VŠECHNY výpočtově
     * kritické domény rulesetu. Seznam se proto neudržuje ručně — odvozuje se
     * z registry a rok navíc se zpřístupní přidáním rulesetu, ne novou verzí
     * aplikace. Rok bez rulesetu tu nikdy nesmí svítit jako podporovaný.
     *
     * @var non-empty-list<PayrollRulesetDomain>
     */
    private const REQUIRED_DOMAINS = [
        PayrollRulesetDomain::IncomeTax,
        PayrollRulesetDomain::SocialInsurance,
        PayrollRulesetDomain::HealthInsurance,
        PayrollRulesetDomain::EmploymentThresholds,
        PayrollRulesetDomain::CompensationAverages,
    ];

    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    /** @return list<int> */
    public function supportedYears(): array
    {
        return PayrollRulesetYearCoverage::commonYears($this->rulesets, self::REQUIRED_DOMAINS);
    }

    public function supportsYear(int $year): bool
    {
        return in_array($year, $this->supportedYears(), true);
    }

    /**
     * Produktový rozsah a aktuální implementační dostupnost se vracejí odděleně.
     * UI tak nikdy nepředstírá, že je plánovaný právní scénář už bezpečně použitelný.
     *
     * @return array{
     *   version:string,
     *   supported_years:list<int>,
     *   employment_types:list<array{key:string,status:string,available:bool,min_epic:string}>,
     *   features:list<array{key:string,status:string,available:bool,min_epic:string}>
     * }
     */
    public function all(): array
    {
        return [
            'version' => self::VERSION,
            'supported_years' => $this->supportedYears(),
            'employment_types' => [
                ['key' => 'hpp', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-05'],
                ['key' => 'dpp', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-05'],
                ['key' => 'dpc', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-05'],
                ['key' => 'statutory_body', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-05'],
                ['key' => 'foreign_regime', 'status' => 'manual_review', 'available' => false, 'min_epic' => 'MZ-10'],
            ],
            'features' => [
                ['key' => 'module_shell', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-00'],
                ['key' => 'activation', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-00'],
                ['key' => 'employer_settings', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-03'],
                ['key' => 'persons', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-04'],
                ['key' => 'employments', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-05'],
                ['key' => 'time_attendance', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-06'],
                ['key' => 'absences', 'status' => 'manual_review', 'available' => true, 'min_epic' => 'MZ-07'],
                ['key' => 'average_earnings', 'status' => 'manual_review', 'available' => true, 'min_epic' => 'MZ-07'],
                ['key' => 'leave_ledger', 'status' => 'manual_review', 'available' => true, 'min_epic' => 'MZ-07'],
                ['key' => 'dpn_compensation', 'status' => 'manual_review', 'available' => true, 'min_epic' => 'MZ-07'],
                ['key' => 'enforcement_deductions', 'status' => 'manual_review', 'available' => true, 'min_epic' => 'MZ-14'],
                ['key' => 'payroll_runs', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-09'],
                ['key' => 'payslips', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-16'],
                // Zápočtový list (§ 313 odst. 1), oddělené potvrzení pro Úřad
                // práce (§ 313 odst. 2) i samostatné potvrzení o průměrném
                // výdělku (§ 356 odst. 1 a 2) mají schválený snapshot, renderer
                // i obrazovku. Zůstává `manual_review`, protože čistý měsíční
                // průměr se počítá jen tam, kde je celý podklad doložený —
                // jinak dokument fail-closed odmítne vzniknout.
                ['key' => 'employment_exit_documents', 'status' => 'manual_review', 'available' => true, 'min_epic' => 'MZ-16'],
                ['key' => 'automatic_posting', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-18'],
                // Automatizovaný je jen standardní scénář 1. Zvláštní scénáře
                // mají připnuté zdrojové matice a XSD, ale bez samostatných
                // právních důkazních modelů musí zůstat fail-closed.
                ['key' => 'jmhz_export', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-22'],
                ['key' => 'jmhz_special_scenarios', 'status' => 'manual_review', 'available' => false, 'min_epic' => 'MZ-22'],
                ['key' => 'jmhz_submission', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-22'],
                ['key' => 'registration_submission', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-22'],
                // Export pro zdravotní pojišťovny je dostupný: modul vyhodnotí
                // oznamovací povinnost, sestaví přehled o platbě, ověří ho
                // připnutým XSD a vydá XML ke stažení — a účetní se k tomu
                // proklikne (Podání a hlášení → ZP — oznámení). Neodesílá se:
                // ani jedna ze sedmi pojišťoven nemá doloženou transportní
                // obálku, což je `direct_submission` níž. Vlajka se překlopila
                // až s obrazovkou, protože hotové jádro bez cesty k němu je
                // z pohledu uživatele nedostupná funkce.
                ['key' => 'health_insurer_export', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-23'],
                ['key' => 'health_insurer_submission', 'status' => 'supported', 'available' => true, 'min_epic' => 'MZ-23'],
                ['key' => 'eldp_control_export', 'status' => 'manual_review', 'available' => true, 'min_epic' => 'MZ-22'],
                ['key' => 'eldp_submission', 'status' => 'not_supported', 'available' => false, 'min_epic' => 'MZ-22'],
                // Obecné automatické odesílání libovolné agendy není bezpečný
                // fallback. Podporované jsou jen výše uvedené konkrétní toky.
                ['key' => 'direct_submission', 'status' => 'not_supported', 'available' => false, 'min_epic' => 'MZ-27'],
            ],
        ];
    }
}
