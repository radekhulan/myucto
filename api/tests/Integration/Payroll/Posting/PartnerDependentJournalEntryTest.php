<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Posting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Payroll\PayrollAccountingDefaults;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Účetní zápis odměny jednatele-společníka za měsíc, kdy hrubá odměna nedosáhne
 * minimálního vyměřovacího základu zdravotního pojištění.
 *
 * Zadání je ruční zápis účetní pro 07/2026: hrubá odměna 4 500 Kč, prohlášení
 * k dani NEpodepsané, čistá odměna se nevyplácí, ale započítává proti účtu ke
 * společníkům. Test staví scénář od nuly a porovnává deník řádek po řádku, aby
 * se dalo říct, jestli aplikace při správně nastaveném společníkovi vygeneruje
 * TENTÝŽ zápis — a když ne, kde přesně se rozchází.
 *
 * Aritmetika cíle (vše v Kč):
 *   zaměstnavatel  24,8 % × 4 500 = 1 116 (soc.) + 9 % × 4 500 = 405 (zdrav.)
 *   zaměstnanec     7,1 % × 4 500 =   320 (soc.)
 *   zdravotní zaměstnance = 13,5 % × 22 400 − 405 = 2 619 (dopočet do minima)
 *   záloha na daň  15 % × 4 500 = 675 (bez slevy na poplatníka)
 *   čistá odměna   4 500 − 320 − 2 619 − 675 = 886 → zápočet na 365.100
 */
#[Group('integration')]
final class PartnerDependentJournalEntryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD_START = '2026-07-01';
    private const GROSS_MINOR = 450_000;

    /** Sloupce předkontací v `payroll_employer_settings`. */
    private const ACCOUNT_COLUMNS = [
        'employment_gross_debit' => 'employment_gross_debit_account',
        'employment_gross_credit' => 'employment_gross_credit_account',
        'partner_gross_debit' => 'partner_gross_debit_account',
        'partner_gross_credit' => 'partner_gross_credit_account',
        'statutory_gross_debit' => 'statutory_gross_debit_account',
        'statutory_gross_credit' => 'statutory_gross_credit_account',
        'employer_insurance_debit' => 'employer_insurance_debit_account',
        'social_insurance_credit' => 'social_insurance_credit_account',
        'health_insurance_credit' => 'health_insurance_credit_account',
        'income_tax_credit' => 'income_tax_credit_account',
        'other_deductions_credit' => 'other_deductions_credit_account',
        'partner_settlement_credit' => 'partner_settlement_credit_account',
        'risky_savings_debit' => 'risky_savings_debit_account',
        'risky_savings_credit' => 'risky_savings_credit_account',
        'employee_receivable_debit' => 'employee_receivable_debit_account',
        'non_deductible_benefit_debit' => 'non_deductible_benefit_debit_account',
        'travel_expense_debit' => 'travel_expense_debit_account',
    ];

    /** Analytiky, na kterých účetní zadavatele zápis vede. */
    private const ACCOUNTS = [
        'employment_gross_debit' => '521.100',
        'employment_gross_credit' => '331.100',
        'partner_gross_debit' => '521.100',
        'partner_gross_credit' => '331.100',
        'statutory_gross_debit' => '521.100',
        'statutory_gross_credit' => '331.100',
        'employer_insurance_debit' => '524.100',
        'social_insurance_credit' => '336.100',
        'health_insurance_credit' => '336.200',
        'income_tax_credit' => '342.200',
        'other_deductions_credit' => '379',
        'partner_settlement_credit' => '365.100',
        'risky_savings_debit' => '527',
        'risky_savings_credit' => '379',
        'employee_receivable_debit' => '335',
        'non_deductible_benefit_debit' => '528',
        'travel_expense_debit' => '512',
    ];

    /**
     * Cílový zápis účetní, řádek po řádku: [účet, strana, částka v haléřích].
     *
     * Účetní ho píše po účetních případech (hrubá odměna, pojistné
     * zaměstnavatele, srážky zaměstnance, záloha na daň, zápočet), takže tentýž
     * účet a strana se opakují. Deník aplikace stejný účet a stranu SLUČUJE do
     * jednoho řádku — proto se porovnává úhrn per účet a strana, ne pořadí
     * řádků. Ekonomicky je to týž zápis; kdo chce vidět jednotlivé případy,
     * najde je v `PayrollPostingPreview::$targetAllocations`.
     *
     * @var list<array{0:string,1:string,2:int}>
     */
    private const EXPECTED_LINES = [
        ['521.100', 'debit', 450_000],
        ['331.100', 'credit', 450_000],
        ['524.100', 'debit', 152_100],
        ['336.100', 'credit', 111_600],
        ['336.200', 'credit', 40_500],
        ['331.100', 'debit', 293_900],
        ['336.100', 'credit', 32_000],
        ['336.200', 'credit', 261_900],
        ['331.100', 'debit', 67_500],
        ['342.200', 'credit', 67_500],
        ['331.100', 'debit', 88_600],
        ['365.100', 'credit', 88_600],
    ];

    private ContainerInterface $container;
    private Connection $db;
    private int $supplierId;
    private int $actorId;
    private int $officeId;
    private int $employeeId;
    private int $employmentId;
    private int $componentId;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $db = $this->container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        $this->db = $db;
        foreach ([
            'payroll_runs',
            'payroll_run_revisions',
            'payroll_posting_batches',
            'payroll_payout_rules',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0) {
            self::markTestSkipped('Chybí zdrojová firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            "UPDATE supplier
                SET payroll_enabled = 1,
                    accounting_mode = 'double_entry',
                    company_name = 'Syntetická společnost',
                    display_name = 'Syntetická společnost',
                    ic = '00000019',
                    street = 'Zkušební',
                    street_number_pop = '12',
                    zip = '110 00',
                    city = 'Praha 1'
              WHERE id = ?",
        )->execute([$this->supplierId]);
        $this->actorId = $this->createActor();

        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->actorId]);
        $policies = $this->service(PayrollEmployerPolicyRepository::class);
        $policies->create($this->supplierId, [
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'payday_day' => 10,
            'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day',
            'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'not_used',
            'travel_expense_policy' => 'not_used',
            'leave_entitlement_weeks' => 4,
            'automatic_posting_enabled' => false,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:partner-posting-policy',
        ], $this->actorId);

        $this->service(AccountingModeRepository::class)
            ->record($this->supplierId, '2026-01-01', 'double_entry');
        $pdo->prepare(
            'INSERT INTO accounting_periods
                (supplier_id, fiscal_year, starts_on, ends_on, status)
             VALUES (?, 2026, "2026-01-01", "2026-12-31", "open")',
        )->execute([$this->supplierId]);
        $this->service(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->seedAnalyticAccounts();

        $this->officeId = $this->createOffice();
        $this->seedEmployerSettings();
        $this->seedEmployee();
        $this->seedStatutoryEvidence();
        $this->seedOpeningBalances();
        $this->seedPartnerSettlementPayout();
        $this->seedWageComponent();
        $this->seedWage();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /**
     * Jádro ověření: běh doběhne do `posted` a deník nese přesně ty účty,
     * strany a částky, které účetní zadavatele napsala ručně.
     */
    public function testPartnerRemunerationIsPostedExactlyAsTheAccountantWroteIt(): void
    {
        $lines = $this->runAndPost();
        self::assertSame(
            self::expectedTotals(),
            $lines,
            "Deník neodpovídá zápisu účetní.\nSkutečnost:\n"
            . CanonicalJson::encode($lines),
        );
        self::assertSame(
            [1_052_100, 1_052_100],
            [
                array_sum(array_filter(
                    $lines,
                    static fn (string $key): bool => str_ends_with($key, '|debit'),
                    ARRAY_FILTER_USE_KEY,
                )),
                array_sum(array_filter(
                    $lines,
                    static fn (string $key): bool => str_ends_with($key, '|credit'),
                    ARRAY_FILTER_USE_KEY,
                )),
            ],
            'Obraty deníku musí sedět na 10 521 Kč na obou stranách.',
        );
    }

    /**
     * Kontrolní protipól: na VÝCHOZÍ sadě předkontací je zápis ve všech
     * částkách týž, ale příjem společníka končí na 522 a závazek na 366.
     *
     * Test současně ukazuje, kde nastavovat NENÍ potřeba nic. Účty 365 a 524
     * zůstaly ve výchozí syntetické podobě a přesto se v deníku objeví jako
     * 365.100 / 524.100 — {@see \MyInvoice\Service\Accounting\PostingService}
     * přesměruje syntetiku na její JEDINOU aktivní daňovou analytiku. U 522
     * a 366 žádná analytika není, takže se nepřesměruje nic a rozdíl proti
     * zápisu účetní zůstane.
     *
     * Daň už tenhle přesměrovací mechanismus neukazuje: od rozpadu srážkové
     * daně (Ú-13) je výchozí předkontace rovnou analytika 342.100 (záloha na
     * daň), takže není co přesměrovávat. Odměna společníka je záloha, ne daň
     * zvláštní sazbou — proto 342.100, a nikoli 342.200.
     *
     * Bez tohohle testu by z prvního nešlo poznat, které nastavení sedící zápis
     * skutečně drží.
     */
    public function testDefaultAccountSetPostsPartnerIncomeTo522(): void
    {
        $this->overwriteEmployerAccounts(PayrollAccountingDefaults::codes());
        $this->db->pdo()->prepare(
            'UPDATE payroll_payout_rules SET destination_reference = "365"
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $this->employeeId]);

        $lines = $this->runAndPost();

        self::assertSame(
            [
                '336.100|credit' => 143_600,
                '336.200|credit' => 302_400,
                '342.100|credit' => 67_500,
                '365.100|credit' => 88_600,
                '366|credit' => 450_000,
                '366|debit' => 450_000,
                '522|debit' => 450_000,
                '524.100|debit' => 152_100,
            ],
            $lines,
            CanonicalJson::encode($lines),
        );
    }

    /**
     * Celý řetězec běhu až do `posted`.
     *
     * @return array<string,int> obraty deníku per účet a strana
     */
    private function runAndPost(): array
    {
        $commands = $this->service(PayrollRunCommandService::class);
        $run = $commands->createRun(
            $this->supplierId,
            self::PERIOD_START,
            '2026-08-15',
            null,
            $this->actorId,
        );
        $locked = $commands->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'partner-posting-lock',
            $this->actorId,
        );
        $calculated = $commands->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'partner-posting-calculate',
            $this->actorId,
        );
        $revisionId = (int) $calculated->revision['id'];
        self::assertSame(
            [],
            $this->blockingValidations($revisionId),
            CanonicalJson::encode($calculated->revision['result_snapshot']['statutory'] ?? []),
        );

        // Mzdová strana je předpokladem účetní: kdyby se rozešla tady, rozdíl
        // v deníku by se dal vysvětlit dvěma způsoby a nález by byl bezcenný.
        $this->assertStatutoryAmounts($calculated->revision['result_snapshot']);

        $reviewed = $commands->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'partner-posting-review',
            $this->actorId,
        );
        $approved = $commands->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'partner-posting-approve',
            $this->actorId,
        );
        self::assertSame('approved', $approved->run['status']);

        $posted = $commands->post(
            $this->supplierId,
            (int) $run['id'],
            (int) $approved->run['row_version'],
            'partner-posting-post',
            $this->actorId,
        );
        self::assertSame('posted', $posted->run['status']);

        return $this->journalLines($revisionId);
    }

    /**
     * Cílový zápis účetní sloučený per účet a strana — tvar, ve kterém deník
     * vzniká.
     *
     * @return array<string,int>
     */
    private static function expectedTotals(): array
    {
        $totals = [];
        foreach (self::EXPECTED_LINES as [$account, $side, $amountMinor]) {
            $key = "{$account}|{$side}";
            $totals[$key] = ($totals[$key] ?? 0) + $amountMinor;
        }
        ksort($totals, SORT_STRING);

        return $totals;
    }

    /** @param array<string,mixed> $resultSnapshot */
    private function assertStatutoryAmounts(array $resultSnapshot): void
    {
        $statutory = $resultSnapshot['statutory'] ?? [];
        self::assertIsArray($statutory);
        $person = $statutory['people'][0] ?? null;
        self::assertIsArray($person, CanonicalJson::encode($statutory));

        $health = $person['health_insurance'];
        // `employee_contribution_minor_units` už dopočet do minima obsahuje;
        // `employee_minimum_top_up_minor_units` je jeho rozpad, ne přírůstek.
        self::assertSame(
            261_900,
            (int) $health['employee_contribution_minor_units'],
            'Zdravotní pojištění zaměstnance včetně dopočtu do minima: '
            . CanonicalJson::encode($health),
        );
        self::assertSame(
            241_600,
            (int) $health['employee_minimum_top_up_minor_units'],
            CanonicalJson::encode($health),
        );
        self::assertSame(
            40_500,
            (int) $health['employer_contribution_minor_units'],
            CanonicalJson::encode($health),
        );

        $social = $person['social_insurance'];
        self::assertSame(
            32_000,
            (int) $social['employee_contribution_minor_units'],
            CanonicalJson::encode($social),
        );
        // Sociální pojistné zaměstnavatele se počítá za celou firmu (sazba se
        // aplikuje na úhrn základů), takže je v kořeni balíku, ne u osoby.
        self::assertSame(
            111_600,
            (int) $statutory['employer_social_minor_units'],
            CanonicalJson::encode($social),
        );

        $advance = $person['income_tax']['advance_tax'] ?? null;
        self::assertIsArray(
            $advance,
            'Záloha na daň bez slevy na poplatníka: '
            . CanonicalJson::encode($person['income_tax']),
        );
        self::assertSame(
            67_500,
            (int) $advance['tax_after_credits_minor_units'],
            CanonicalJson::encode($advance),
        );
    }

    /**
     * Obraty deníku per účet a strana, v haléřích.
     *
     * @return array<string,int>
     */
    private function journalLines(int $revisionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT account.account_code, line.side, line.amount
               FROM payroll_posting_batches batch
               JOIN journal_entry_lines line
                 ON line.supplier_id = batch.supplier_id
                AND line.entry_id = batch.journal_entry_id
               JOIN chart_of_accounts account
                 ON account.id = line.account_id
              WHERE batch.supplier_id = ? AND batch.revision_id = ?
              ORDER BY line.id',
        );
        $statement->execute([$this->supplierId, $revisionId]);

        $lines = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $key = "{$line['account_code']}|{$line['side']}";
            $lines[$key] = ($lines[$key] ?? 0)
                + (int) round(((float) $line['amount']) * 100);
        }
        ksort($lines, SORT_STRING);

        return $lines;
    }

    /** @return list<array<string,mixed>> */
    private function blockingValidations(int $revisionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT code, message
               FROM payroll_run_validations
              WHERE supplier_id = ? AND revision_id = ? AND severity = "blocker"
              ORDER BY code, id',
        );
        $statement->execute([$this->supplierId, $revisionId]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Analytiky, které směrná osnova nenese (521.100, 331.100, 342.200, 365.100,
     * 524.100). Bez nich by nastavení mezd ani nešlo uložit — validátor i
     * `PostingService` chtějí účet, který firma v osnově opravdu má.
     *
     * Zakládá se idempotentně: 342.200 od rozpadu srážkové daně (Ú-13) šablona
     * osnovy sype sama, takže slepý INSERT padal na `uq_coa_supplier_code`.
     * Test potřebuje jen jistotu, že analytika existuje — ne že ji zavedl on.
     */
    private function seedAnalyticAccounts(): void
    {
        $pdo = $this->db->pdo();
        $parent = $pdo->prepare(
            'SELECT id, account_type, normal_side, tax_deductibility
               FROM chart_of_accounts
              WHERE supplier_id = ? AND account_code = ?',
        );
        $existing = $pdo->prepare(
            'SELECT 1 FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?',
        );
        $insert = $pdo->prepare(
            'INSERT INTO chart_of_accounts
                (supplier_id, account_code, name, account_type, normal_side,
                 is_synthetic, parent_id, is_active, tax_deductibility)
             VALUES (?, ?, ?, ?, ?, 0, ?, 1, ?)',
        );
        foreach ([
            '521.100' => 'Mzdové náklady',
            '331.100' => 'Zaměstnanci',
            '342.200' => 'Ostatní přímé daně',
            '365.100' => 'Ostatní dluhy ke společníkům',
            '524.100' => 'Zákonné sociální pojištění',
        ] as $code => $name) {
            $existing->execute([$this->supplierId, $code]);
            if ($existing->fetchColumn() !== false) {
                continue;
            }
            $parent->execute([$this->supplierId, substr($code, 0, 3)]);
            $row = $parent->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($row, "Osnova nemá syntetiku " . substr($code, 0, 3) . '.');
            $insert->execute([
                $this->supplierId,
                $code,
                $name,
                (string) $row['account_type'],
                $row['normal_side'],
                (int) $row['id'],
                (string) $row['tax_deductibility'],
            ]);
        }
    }

    private function createOffice(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "SYN", "Syntetické pracoviště", "0000001900", 1)',
        )->execute([$this->supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from,
                 social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2026-01-01", "0000001900", "synthetic:partner-posting")',
        )->execute([$this->supplierId, $officeId]);

        return $officeId;
    }

    /**
     * Předkontace zadavatele. Společník má ZÁMĚRNĚ 521.100/331.100, ne výchozí
     * 522/366 — právě to je nastavení, na kterém zápis stojí.
     */
    private function seedEmployerSettings(): void
    {
        $names = ['supplier_id', 'default_office_id', 'social_security_office_code'];
        $values = [$this->supplierId, $this->officeId, 'P'];
        foreach (self::ACCOUNT_COLUMNS as $key => $column) {
            $names[] = $column;
            $values[] = self::ACCOUNTS[$key];
        }
        $placeholders = implode(', ', array_fill(0, count($names), '?'));
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings (' . implode(', ', $names) . ')'
            . " VALUES ({$placeholders})",
        )->execute($values);
    }

    /** @param array<string,string> $accounts */
    private function overwriteEmployerAccounts(array $accounts): void
    {
        $assignments = [];
        $values = [];
        foreach (self::ACCOUNT_COLUMNS as $key => $column) {
            $assignments[] = "{$column} = ?";
            $values[] = $accounts[$key];
        }
        $values[] = $this->supplierId;
        $this->db->pdo()->prepare(
            'UPDATE payroll_employer_settings SET ' . implode(', ', $assignments)
            . ' WHERE supplier_id = ?',
        )->execute($values);
    }

    private function seedEmployee(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, tax_declaration_signed,
                 tax_credit_taxpayer, child_count, monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetický Společník", "employee", 0, 0, 0, 0, 0, 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status, payout_method,
                 partner_settlement_account_code)
             VALUES (?, ?, "ready", ?, ?)',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            'partner_settlement',
            self::ACCOUNTS['partner_settlement_credit'],
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_primary)
             VALUES (?, ?, ?, "SYN-SPOL", "partner_dependent", "active",
                     "2026-01-01", "2026-01-01", ?, 1)',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->officeId,
            self::GROSS_MINOR,
        ]);
        $this->employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from,
                 planned_start_on, actual_start_on, weekly_hours,
                 workload_basis_points, social_insurance_participation,
                 health_insurance_participation, tax_regime,
                 other_withholding_eligibility, tax_declaration_signed, is_primary)
             VALUES (?, ?, ?, "2026-01-01", "2026-01-01", "2026-01-01",
                     40, 10000, "automatic", "automatic", "advance",
                     "ineligible", 0, 1)',
        )->execute([$this->supplierId, $this->employmentId, $this->officeId]);
    }

    private function seedStatutoryEvidence(): void
    {
        $evidence = $this->service(PayrollPersonStatutoryEvidenceRepository::class);
        $evidence->save(
            $this->supplierId,
            $this->employeeId,
            [
                'effective_on' => date('Y-m-t', strtotime(self::PERIOD_START)),
                'sections' => [
                    'tax_declarations' => [[
                        'status' => 'not-signed',
                        'evidence_reference' => 'document:synthetic-tax-declaration',
                        'effective_from' => '2026-01-01',
                        'effective_to' => null,
                    ]],
                    'tax_residences' => [[
                        'residence' => 'czech-resident',
                        'country_code' => 'CZ',
                        'evidence_reference' => 'document:synthetic-tax-residence',
                        'effective_from' => '2026-01-01',
                        'effective_to' => null,
                    ]],
                    'social_jurisdictions' => [[
                        'jurisdiction' => 'czech_regime_verified',
                        'foreign_country_code' => null,
                        'jurisdiction_evidence_reference' => null,
                        'a1_status' => 'not_applicable',
                        'a1_certificate_reference' => null,
                        'a1_valid_until' => null,
                        'effective_from' => '2026-01-01',
                        'effective_to' => null,
                    ]],
                    'social_discount_claims' => [[
                        'status' => 'not_claimed',
                        'evidence_reference' => null,
                        'effective_from' => '2026-01-01',
                        'effective_to' => null,
                    ]],
                    'health_coverages' => [[
                        'jurisdiction' => 'czech_regime_verified',
                        'foreign_country_code' => null,
                        'jurisdiction_evidence_reference' => null,
                        'insurer_status' => 'verified',
                        'insurer_code' => '111',
                        'insurer_evidence_reference' => 'document:synthetic-health-card',
                        'health_evidence_document_id' => $this->createHealthEvidenceDocument(),
                        'effective_from' => '2026-01-01',
                        'effective_to' => null,
                    ]],
                    // Bez řádku měsíční evidence platí zákonný default podle
                    // § 3 odst. 10 z. 592/1992 Sb.: dopočet do minima hradí
                    // zaměstnanec. Přesně tak ho vede i účetní zadavatele.
                    'health_month_evidence' => [],
                ],
            ],
            date('Y-m-t', strtotime(self::PERIOD_START)),
            $this->actorId,
            null,
            'partner-posting-test',
        );
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_person_month_evidence
                (supplier_id, employee_id, period_start,
                 claim_register_evidence_complete, dependants_evidence_complete,
                 spouse_evidence_complete, pension_evidence, updated_by)
             VALUES (?, ?, ?, 1, 1, 1, "none", ?)',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            self::PERIOD_START,
            $this->actorId,
        ]);
    }

    private function createHealthEvidenceDocument(): int
    {
        $sha256 = hash('sha256', 'partner-posting-health-evidence');
        $this->db->pdo()->prepare(
            'INSERT INTO documents
                (supplier_id, title, original_name, filename, sha256, mime_type,
                 size_bytes, doc_type, source, uploaded_by, scope)
             VALUES (?, "Syntetický zdravotní důkaz", "health-evidence.pdf", ?, ?,
                     "application/pdf", 1, "pdf", "manual", ?, "company")',
        )->execute([$this->supplierId, $sha256 . '.pdf', $sha256, $this->actorId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function seedOpeningBalances(): void
    {
        $accumulators = $this->service(PayrollStatutoryAccumulatorRepository::class);
        $accumulators->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'social_insurance',
            ['assessment_base_minor_units' => 0],
            'synthetic:partner-posting-social-opening',
            ['verified_zero' => true],
            'partner-posting-social-opening',
            actorUserId: $this->actorId,
        );
        $accumulators->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'income_tax',
            [
                'completed_months' => 0,
                'advance_base_minor_units' => 0,
                'withholding_base_minor_units' => 0,
                'advance_tax_minor_units' => 0,
                'withholding_tax_minor_units' => 0,
                'applied_non_refundable_credits_minor_units' => 0,
                'applied_child_credit_minor_units' => 0,
                'tax_bonus_minor_units' => 0,
                'bonus_qualifying_income_minor_units' => 0,
            ],
            'synthetic:partner-posting-tax-opening',
            ['verified_zero' => true],
            'partner-posting-tax-opening',
            actorUserId: $this->actorId,
        );
    }

    /** Čistá odměna se nevyplácí — celý zbytek jde zápočtem na 365.100. */
    private function seedPartnerSettlementPayout(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payout_rules
                (supplier_id, employee_id, allocation_reference,
                 destination_kind, destination_reference, allocation_kind,
                 priority_no, is_active)
             VALUES (?, ?, "PARTNER-SETTLEMENT", "partner_settlement", ?,
                     "remainder", 100, 1)',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            self::ACCOUNTS['partner_settlement_credit'],
        ]);
    }

    /**
     * Složka BEZ vlastní předkontace: účty se mají vzít z pracovního vztahu,
     * tedy z nastavení `partner_gross_debit`/`partner_gross_credit`. Kdyby
     * složka nesla vlastní 521/331, test by ověřoval složku, ne nastavení
     * společníka.
     */
    private function seedWageComponent(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment,
                 jmhz_treatment, statistics_treatment, valid_from)
             VALUES (?, "ODMENA_SPOLECNIK", "Odměna společníka", "base_wage",
                     "monetary", "regular", "included", "included", "included",
                     "included", "included", "included", "included",
                     "included", "included", "2026-01-01")',
        )->execute([$this->supplierId]);
        $this->componentId = (int) $this->db->pdo()->lastInsertId();
    }

    private function seedWage(): void
    {
        $snapshot = [
            'code' => 'ODMENA_SPOLECNIK',
            'name' => 'Odměna společníka',
            'component_kind' => 'base_wage',
            'value_kind' => 'monetary',
            'frequency_kind' => 'regular',
            'tax_treatment' => 'included',
            'social_participation_treatment' => 'included',
            'social_treatment' => 'included',
            'health_participation_treatment' => 'included',
            'health_treatment' => 'included',
            'average_earning_treatment' => 'included',
            'enforcement_treatment' => 'included',
            'jmhz_treatment' => 'included',
            'statistics_treatment' => 'included',
            'accounting_debit_code' => null,
            'accounting_credit_code' => null,
            'annual_limit_minor' => null,
            'component_id' => $this->componentId,
            'component_row_version' => 1,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ];
        $json = CanonicalJson::encode($snapshot);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id,
                 period_start, amount_minor, source_kind, status,
                 component_snapshot_json, component_snapshot_hash,
                 approved_by, approved_at)
             VALUES (?, ?, ?, ?, ?, ?, "manual", "approved", ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $this->componentId,
            self::PERIOD_START,
            self::GROSS_MINOR,
            $json,
            hash('sha256', $json, true),
            $this->actorId,
        ]);
    }

    private function createActor(): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Synthetic payroll actor", "readonly", "cs", 1)',
        )->execute([
            'partner-posting-' . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->container->get($class);
        if (!$service instanceof $class) {
            throw new \RuntimeException("Služba {$class} není dostupná.");
        }

        return $service;
    }
}
