<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDependantAction;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\TaxStatement\TaxStatementService;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Vyúčtování daně za rok 2026 ze skutečných mzdových běhů malé firmy.
 *
 * Dosavadní testy vyúčtování ({@see PayrollTaxStatementTest}) skládají
 * podklad z ručně vložených úhrnů. Tady vznikne podklad tak, jak ho vyrobí
 * účetní: čtyři lidé, běhy za leden až březen 2026 a nad nimi vyúčtování
 * zálohové daně (DPZVD6, § 38j odst. 4 ZDP ve znění do 31. 12. 2026)
 * a vyúčtování daně vybírané srážkou (DPSVD2, § 38d odst. 8 ZDP).
 *
 * Očekávané částky jsou ručně spočtené podle ZDP a sady 2026
 * ({@see \MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026}),
 * ne opsané z výstupu:
 *
 *  • A: HPP s prohlášením, 24 000 Kč, dvě děti (1. a 2. pořadí).
 *    Záloha 15 % z 24 000 = 3 600; sleva na poplatníka 2 570 → 1 030;
 *    zvýhodnění 1 267 + 1 860 = 3 127 → sleva na dani 1 030, bonus 2 097.
 *    Sražená záloha 0, vyplacený bonus 2 097 (§ 35c, § 35d odst. 4).
 *  • B: HPP bez prohlášení, 30 000 Kč. Záloha 15 % = 4 500, bez slev (§ 38k).
 *  • C: DPP bez prohlášení. Leden 8 000, únor 0 Kč (vztah trvá, účetní zadá
 *    základní složku 0 Kč), březen 11 000 Kč.
 *    Pod rozhodnou částkou 12 000 Kč (§ 6 odst. 4 písm. a) bod 2 ZDP) jde
 *    srážková daň 15 % (§ 36 odst. 2 písm. b): 1 200 a 1 650 Kč.
 *  • D: DPČ s prohlášením, 24 000 Kč. Záloha 3 600 − 2 570 = 1 030.
 *
 * Leden: zálohy 0 + 4 500 + 1 030 = 5 530, bonus 2 097, srážka 1 200.
 * Únor:  zálohy 5 530, bonus 2 097, srážka 0.
 * Březen: zálohy 5 530, bonus 2 097, srážka 1 650.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2ePodaniTaxStatementTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const MONTHS = ['2026-01', '2026-02', '2026-03'];

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('VYU', 'Syntetická účtárna vyúčtování', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $this->baseComponentId = $this->createComponent('MZDA_VYU', 'base_wage', 'regular');
        $this->db->pdo()->prepare(
            'UPDATE supplier SET financial_office_code = "451", taxpayer_type = "po", dic = "CZ00000019"
              WHERE id = ?',
        )->execute([$this->supplierId]);
        // Mzdový můstek do účetnictví (post) potřebuje režim, období a osnovu.
        $modes = $this->container->get(AccountingModeRepository::class);
        self::assertInstanceOf(AccountingModeRepository::class, $modes);
        $modes->record($this->supplierId, '2026-01-01', 'double_entry');
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
             VALUES (?, 2026, "2026-01-01", "2026-12-31", "open")',
        )->execute([$this->supplierId]);
        $chart = $this->container->get(ChartOfAccountsSeeder::class);
        self::assertInstanceOf(ChartOfAccountsSeeder::class, $chart);
        $chart->seedForSupplier($this->supplierId);
        // Platební řetěz jde přes produkční příkazovou službu z kontejneru.
        $commands = $this->container->get(PayrollRunCommandService::class);
        self::assertInstanceOf(PayrollRunCommandService::class, $commands);
        $this->runs = $commands;
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testStatementsFromRealRunsMatchHandCalculatedMonthlyTotals(): void
    {
        $people = $this->enterSmallCompany();
        // Roční zúčtování za 2025 provedené 20. 2. 2026 u D: přeplatek 1 500 Kč
        // se vrací v nejbližší mzdě zúčtované po provedení, nejpozději ve mzdě
        // za březen (§ 38ch odst. 4 ZDP), tedy ve mzdě za únor.
        $this->seedPriorYearOverpayment($people['D']['employee_id'], 150_000, '2026-02-20');
        $results = [];
        foreach (self::MONTHS as $period) {
            $results[$period] = $this->runMonth($period, $people);
        }
        $remitted = $this->remittedTax();
        // Odvod finančnímu úřadu (§ 38h odst. 3, § 35d odst. 5, § 38ch odst. 5):
        // zálohy minus vyplacené bonusy minus vrácený přeplatek.
        self::assertSame(['2026-01' => 343_300, '2026-02' => 193_300, '2026-03' => 343_300], $remitted['advance_tax']);
        self::assertSame(['2026-01' => 120_000, '2026-03' => 165_000], $remitted['withholding_tax']);

        // Měsíční výsledek každé osoby (ruční přepočet v hlavičce třídy).
        $january = $results['2026-01'];
        self::assertSame(0, $january['A']['advance_tax_minor_units']);
        self::assertSame(209_700, $january['A']['tax_bonus_minor_units']);
        self::assertSame(450_000, $january['B']['advance_tax_minor_units']);
        self::assertSame(120_000, $january['C']['withholding_tax_minor_units']);
        self::assertSame(0, $january['C']['advance_tax_minor_units']);
        self::assertSame(103_000, $january['D']['advance_tax_minor_units']);
        self::assertSame(165_000, $results['2026-03']['C']['withholding_tax_minor_units']);
        // Přeplatek není příjem: daň D v březnu se nemění, vyplatí se navíc.
        self::assertSame(103_000, $results['2026-03']['D']['advance_tax_minor_units']);
        self::assertSame(103_000, $results['2026-02']['D']['advance_tax_minor_units']);
        self::assertSame(0, $results['2026-01']['D']['annual_settlement_minor_units']);
        self::assertSame(150_000, $results['2026-02']['D']['annual_settlement_minor_units']);
        self::assertSame(0, $results['2026-03']['D']['annual_settlement_minor_units']);

        $service = $this->container->get(TaxStatementService::class);
        self::assertInstanceOf(TaxStatementService::class, $service);
        $preview = $service->preview($this->supplierId, 2026);
        $dpz = $preview['dpzvd6'];
        $dps = $preview['dpsvd2'];

        $byMonth = [];
        foreach ($dpz['months'] as $row) {
            $byMonth[$row['month']] = $row;
        }
        self::assertSame([1, 2, 3], array_keys($byMonth), CanonicalJson::encode($dpz['months']));
        foreach ([1, 2, 3] as $month) {
            self::assertSame(5_530, $byMonth[$month]['advance_due'], "sl. 1 měsíc {$month}");
            self::assertSame(2_097, $byMonth[$month]['bonus_paid'], "sl. 5 měsíc {$month}");
        }
        // Sl. 9 = sl. 1 − sl. 3 − sl. 4 − sl. 5: leden a únor 5 530 − 2 097,
        // únor navíc − 1 500 vráceného přeplatku z ročního zúčtování za 2025.
        self::assertSame(3_433, $byMonth[1]['settled_amount']);
        self::assertSame(1_933, $byMonth[2]['settled_amount']);
        self::assertSame(3_433, $byMonth[3]['settled_amount']);
        self::assertSame(1_500, $byMonth[2]['annual_overpayment']);
        self::assertSame(0, $byMonth[3]['annual_overpayment']);
        // Sl. 11 skutečně odvedeno ze spárovaných plateb = sl. 9 (nic nezůstalo dlužno).
        self::assertSame(3_433, $byMonth[1]['remitted']);
        self::assertSame(1_933, $byMonth[2]['remitted']);
        self::assertSame(3_433, $byMonth[3]['remitted']);
        self::assertSame(1_500, $dpz['annual_overpayment_total'] ?? null, CanonicalJson::encode(array_diff_key($dpz, ['months' => 1, 'warnings' => 1])));
        // Počet zaměstnanců s příjmy podle § 6 podle mzdových listů (poc_zamN).
        // Únor (dohoda s nulovým příjmem) je otázka výkladu, viz report.
        self::assertSame(4, $byMonth[1]['headcount']);
        self::assertSame(4, $byMonth[3]['headcount']);
        // Dohoda s nulovým příjmem v únoru se dnes do počtu započte (4), viz report.
        self::assertSame(4, $byMonth[2]['headcount']);
        self::assertSame(16_590, $dpz['total']['advance_due']);

        $withholding = [];
        foreach ($dps['months'] as $row) {
            $withholding[$row['month']] = $row['tax_due_minor'];
        }
        self::assertSame([1 => 120_000, 3 => 165_000], $withholding);
        self::assertSame(285_000, $dps['total']['tax_due_minor']);
        self::assertSame(285_000, $dps['total']['remitted_minor'] ?? null, CanonicalJson::encode($dps['total']));

        $validator = new XmlSchemaValidator();
        foreach (TaxStatementService::FORMS as $form) {
            self::assertTrue($validator->hasSchema($form), "XSD {$form}");
            $built = $service->build($this->supplierId, 2026, $form);
            $validation = $validator->validate($built['xml'], $form);
            self::assertSame('passed', $validation['status'], $form . ': ' . implode(' | ', $validation['errors']));
        }
    }

    /**
     * Potvrzení o zdanitelných příjmech za 2026 (§ 38j odst. 3 ZDP, tiskopisy
     * 25 5460 a 25 5460/A pro srážkovou daň) ze stejných běhů. Ruční součty:
     *  • A: příjem 3 × 24 000 = 72 000, záloha 0, bonusy 3 × 2 097 = 6 291,
     *    prohlášení 1–3, děti 1. pořadí 3 × 1 267 a 2. pořadí 3 × 1 860;
     *  • B: příjem 90 000, záloha 3 × 4 500 = 13 500, bez prohlášení;
     *  • C (srážka zvlášť): příjem 8 000 + 11 000 = 19 000, daň 1 200 + 1 650 = 2 850.
     */
    public function testTaxableIncomeCertificatesFromRealRunsCarryHandCalculatedTotals(): void
    {
        $people = $this->enterSmallCompany();
        foreach (self::MONTHS as $period) {
            $this->runMonth($period, $people);
        }
        $builder = $this->container->get(AnnualTaxCertificateSnapshotBuilder::class);
        self::assertInstanceOf(AnnualTaxCertificateSnapshotBuilder::class, $builder);

        $a = $builder->build($this->supplierId, $people['A']['employee_id'], 2026, PayrollDocumentKind::TaxableIncomeAdvanceCertificate, $this->actors[0])['document'];
        self::assertSame([1, 2, 3], $a->months);
        self::assertSame(7_200_000, $a->paidIncomeMinorUnits);
        self::assertSame(0, $a->advanceTaxMinorUnits);
        self::assertSame(629_100, $a->taxBonusMinorUnits);
        self::assertSame(0, $a->withholdingTaxMinorUnits);
        self::assertSame([1, 2, 3], $a->taxDeclarationSignedMonths);
        // Řádek dětí: pořadí a měsíce nároku (§ 35c odst. 1 a 6 ZDP).
        self::assertSame(['1–3', ''], [$a->childTaxBenefits[0]['first_child_period'], $a->childTaxBenefits[0]['second_child_period']]);
        self::assertSame(['', '1–3'], [$a->childTaxBenefits[1]['first_child_period'], $a->childTaxBenefits[1]['second_child_period']]);

        $b = $builder->build($this->supplierId, $people['B']['employee_id'], 2026, PayrollDocumentKind::TaxableIncomeAdvanceCertificate, $this->actors[0])['document'];
        self::assertSame([1, 2, 3], $b->months);
        self::assertSame(9_000_000, $b->paidIncomeMinorUnits);
        self::assertSame(1_350_000, $b->advanceTaxMinorUnits);
        self::assertSame(0, $b->taxBonusMinorUnits);
        self::assertSame([], $b->taxDeclarationSignedMonths);

        $c = $builder->build($this->supplierId, $people['C']['employee_id'], 2026, PayrollDocumentKind::TaxableIncomeWithholdingCertificate, $this->actors[0])['document'];
        // Měsíce, ve kterých byl příjem se srážkou; únor s 0 Kč se nevykazuje.
        self::assertSame([1, 3], $c->months);
        self::assertSame(1_900_000, $c->paidIncomeMinorUnits);
        self::assertSame(285_000, $c->withholdingTaxMinorUnits);
        self::assertSame(0, $c->advanceTaxMinorUnits);
    }

    /**
     * @return array<string,array{employee_id:int,employment_id:int,name:string,gross:array<string,?int>}>
     */
    private function enterSmallCompany(): array
    {
        $people = [];
        $people['A'] = $this->hire('Syntetická Rodička', 'hpp', 'employment', 40, true)
            + ['gross' => ['2026-01' => 24_000, '2026-02' => 24_000, '2026-03' => 24_000]];
        $this->child($people['A']['employee_id'], 'Syntetické Prvé', '2018-05-10', 1);
        $this->child($people['A']['employee_id'], 'Syntetické Druhé', '2020-09-03', 2);
        $people['B'] = $this->hire('Syntetický Neprohlášený', 'hpp', 'employment', 40, false)
            + ['gross' => ['2026-01' => 30_000, '2026-02' => 30_000, '2026-03' => 30_000]];
        $people['C'] = $this->hire('Syntetický Dohodář', 'dpp', 'dpp', 10, false)
            + ['gross' => ['2026-01' => 8_000, '2026-02' => 0, '2026-03' => 11_000]];
        $people['D'] = $this->hire('Syntetická Činnostní', 'dpc', 'dpc', 20, true)
            + ['gross' => ['2026-01' => 24_000, '2026-02' => 24_000, '2026-03' => 24_000]];

        return $people;
    }

    /**
     * Zaúčtování, příprava plateb, úhrada všech závazků k výplatnímu dni
     * a potvrzení zaplacení. Bez toho vyúčtování nemá sl. 11 (odvedeno)
     * a potvrzení o příjmech doklad o výplatě (§ 5 odst. 4 ZDP).
     *
     * @param array<string,mixed> $approvedRun
     */
    private function payRun(array $approvedRun, int $revisionId, string $payday, string $period): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_module_state SET status = "active", activated_by = ?, activated_at = NOW() WHERE supplier_id = ?',
        )->execute([$this->actors[0], $this->supplierId]);
        $runId = (int) $approvedRun['id'];
        $posted = $this->runs->post($this->supplierId, $runId, (int) $approvedRun['row_version'], "vyu-post-{$period}", $this->actors[0]);
        self::assertSame('posted', $posted->run['status'], CanonicalJson::encode($posted->outcome?->details ?? []));
        $prepared = $this->runs->preparePayments($this->supplierId, $runId, (int) $posted->run['row_version'], "vyu-pay-{$period}", $this->actors[0]);
        self::assertSame('payment_ready', $prepared->run['status'], CanonicalJson::encode($prepared->outcome?->details ?? []));
        $statement = $this->db->pdo()->prepare(
            'SELECT id, amount_minor FROM payroll_payment_liabilities WHERE supplier_id = ? AND revision_id = ? ORDER BY id',
        );
        $statement->execute([$this->supplierId, $revisionId]);
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $liability) {
            $this->settleLiability((int) $liability['id'], (int) $liability['amount_minor'], $payday);
        }
        $paid = $this->runs->markPaid($this->supplierId, $runId, (int) $prepared->run['row_version'], "vyu-paid-{$period}", $this->actors[0]);
        self::assertSame('paid', $paid->run['status'], CanonicalJson::encode($paid->outcome?->details ?? []));
    }

    /** @return array{advance_tax:array<string,int>,withholding_tax:array<string,int>} */
    private function remittedTax(): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT DATE_FORMAT(run.period_start, "%Y-%m") AS period, liability.liability_kind, SUM(liability.amount_minor) AS amount
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision ON revision.supplier_id = liability.supplier_id AND revision.id = liability.revision_id
               JOIN payroll_runs run ON run.supplier_id = revision.supplier_id AND run.id = revision.run_id
              WHERE liability.supplier_id = ? AND liability.liability_kind IN ("advance_tax", "withholding_tax")
              GROUP BY period, liability.liability_kind
              ORDER BY period',
        );
        $statement->execute([$this->supplierId]);
        $remitted = ['advance_tax' => [], 'withholding_tax' => []];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $remitted[(string) $row['liability_kind']][(string) $row['period']] = (int) $row['amount'];
        }

        return $remitted;
    }

    private function settleLiability(int $liabilityId, int $amountMinor, string $paidOn): void
    {
        $pdo = $this->db->pdo();
        $reference = 'vyu-' . bin2hex(random_bytes(6));
        $pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 planned_payment_date, payer_reference, declared_total_minor,
                 declared_item_count, snapshot_ciphertext, snapshot_hash,
                 idempotency_key_hash, created_by)
             VALUES (?, ?, "bank", "manual", ?, "synthetic-payer", ?, 1, "enc:v2:synthetic-batch", ?, UNHEX(?), ?)',
        )->execute([$this->supplierId, $reference, $paidOn, $amountMinor, hash('sha256', "batch:{$reference}"), hash('sha256', "batch-key:{$reference}"), $this->actors[0]]);
        $batchId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference, recipient_reference,
                 amount_minor, instruction_ciphertext, instruction_hash, idempotency_key_hash)
             VALUES (?, ?, ?, "synthetic-recipient", ?, "enc:v2:synthetic-item", ?, UNHEX(?))',
        )->execute([$this->supplierId, $batchId, $reference, $amountMinor, hash('sha256', "item:{$reference}"), hash('sha256', "item-key:{$reference}")]);
        $itemId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_allocations (supplier_id, item_id, liability_id, amount_minor, idempotency_key_hash)
             VALUES (?, ?, ?, ?, UNHEX(?))',
        )->execute([$this->supplierId, $itemId, $liabilityId, $amountMinor, hash('sha256', "allocation-key:{$reference}")]);
        $allocationId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date, source)
             VALUES (?, ?, ?, "1000000005", "0100", "CZK", ?, "gpc")',
        )->execute([$this->supplierId, "{$reference}.gpc", hash('sha256', "statement:{$reference}"), $paidOn]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, description, import_fingerprint)
             VALUES (?, ?, ?, "CZK", ?, ?)',
        )->execute([$statementId, $paidOn, sprintf('-%d.%02d', intdiv($amountMinor, 100), $amountMinor % 100), "Syntetická úhrada {$reference}", hash('sha256', "transaction:{$reference}")]);
        $transactionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, allocation_id, event_kind, amount_minor, bank_statement_id, bank_transaction_id, idempotency_key_hash, matched_by)
             VALUES (?, ?, "matched", ?, ?, ?, UNHEX(?), ?)',
        )->execute([$this->supplierId, $allocationId, $amountMinor, $statementId, $transactionId, hash('sha256', "match:{$reference}"), $this->actors[0]]);
    }

    private function enableBankPayout(int $employeeId, int $sequence): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_person_accounts
                (supplier_id, employee_id, label, bank_account_ciphertext, bank_account_hash, bank_account_masked,
                 allocation_basis_points, effective_from, is_active, row_version, verification_source, verified_on, verified_by)
             VALUES (?, ?, "Syntetický účet", "enc:v2:synthetic-account", UNHEX(?), "••••0005", 10000, "2026-01-01", 1, 1,
                     "user_verified", "2026-01-05", ?)',
        )->execute([$this->supplierId, $employeeId, hash('sha256', "vyu-account:{$this->supplierId}:{$sequence}"), $this->actors[0]]);
        $accountId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payout_rules
                (supplier_id, employee_id, allocation_reference, destination_kind, destination_reference, allocation_kind, priority_no, is_active)
             VALUES (?, ?, ?, "bank", ?, "remainder", 100, 1)',
        )->execute([$this->supplierId, $employeeId, "VYU-REMAINDER-{$sequence}", "account:{$accountId}"]);
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function hire(string $name, string $type, string $relation, int $weekly, bool $declaration): array
    {
        $sequence = ++$this->sequence;

        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            $type,
            $relation,
            $weekly,
            intdiv($weekly * 10_000, 40),
            $declaration,
            '2026-01-01',
            healthTopUpResponsibility: 'employee',
        );
        $this->enableBankPayout($person['employee_id'], $sequence);
        // Jméno a rodné číslo osoby potřebuje potvrzení o příjmech (hlavička tiskopisu).
        [$first, $last] = explode(' ', $name, 2);
        $birthDate = sprintf('19%02d-0%d-1%d', 80 + $sequence, $sequence, $sequence);
        $sex = str_ends_with($last, 'á') ? 'female' : 'male';
        $this->completeJmhzEmployment($person, null, [
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city, postal_code, country_code, effective_from)
             VALUES (?, ?, "residence", "Modelová 2", "Brno", "602 00", "CZ", "2026-01-01")',
        )->execute([$this->supplierId, $person['employee_id']]);

        return $person;
    }

    /**
     * @param array<string,array<string,mixed>> $people
     * @return array<string,array<string,mixed>> klíč osoby => výsledek daně osoby
     */
    private function runMonth(string $period, array $people): array
    {
        $periodStart = "{$period}-01";
        foreach ($people as $key => $person) {
            $this->db->pdo()->prepare(
                'INSERT IGNORE INTO payroll_enforcement_person_month_evidence
                    (supplier_id, employee_id, period_start,
                     claim_register_evidence_complete, dependants_evidence_complete,
                     spouse_evidence_complete, pension_evidence, updated_by)
                 VALUES (?, ?, ?, 1, 1, 1, "none", ?)',
            )->execute([$this->supplierId, $person['employee_id'], $periodStart, $this->actors[0]]);
            $gross = $person['gross'][$period] ?? null;
            if ($gross === null) {
                continue;
            }
            $this->createApprovedInput($person, $this->baseComponentId, $gross * 100, "vyu-{$key}-{$period}", $periodStart);
        }
        $payday = (new \DateTimeImmutable($periodStart))->modify('+1 month')->format('Y-m-12');
        $run = $this->runPayrollMonth($periodStart, $payday, $this->officeId, "vyu-{$period}");
        self::assertSame([], $run['blockers'], "{$period}: " . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], "{$period}: " . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $this->payRun($run['approved']->run, (int) $run['approved']->revision['id'], $payday, $period);

        $byEmployee = [];
        foreach ($people as $key => $person) {
            $byEmployee[$person['employee_id']] = $key;
        }
        $results = [];
        foreach ((array) ($run['approved']->revision['result_snapshot']['statutory']['people'] ?? []) as $row) {
            $key = $byEmployee[(int) substr((string) ($row['person_reference'] ?? ''), strlen('employee:'))] ?? null;
            if ($key !== null) {
                $tax = (array) ($row['income_tax'] ?? []);
                $results[$key] = [
                    'advance_tax_minor_units' => $tax['advance_tax']['tax_after_credits_minor_units'] ?? null,
                    'tax_bonus_minor_units' => $tax['advance_tax']['tax_bonus_minor_units'] ?? null,
                    'withholding_tax_minor_units' => $tax['withholding_tax_minor_units'] ?? null,
                    'annual_settlement_minor_units' => $row['net_pay']['annual_settlement_minor_units'] ?? null,
                ];
            }
        }

        return $results;
    }

    /**
     * Výsledek ročního zúčtování za 2025 tak, jak ho zapíše
     * {@see \MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxSettlementService::settle()}
     * (vzor {@see AnnualSettlementPayoutRunTest}).
     */
    private function seedPriorYearOverpayment(int $employeeId, int $payableMinor, string $settledOn): void
    {
        $pdo = $this->db->pdo();
        $manifest = CanonicalJson::encode(['synthetic' => true]);
        $pdo->prepare(
            'INSERT INTO payroll_annual_document_revisions
                (supplier_id, employee_id, tax_year, purpose, revision_no,
                 snapshot_ciphertext, snapshot_hash, source_manifest_json,
                 source_manifest_hash, approved_by, approved_at)
             VALUES (?, ?, 2025, "annual_settlement_result", 1,
                     "synthetic", ?, ?, ?, ?, NOW())',
        )->execute([$this->supplierId, $employeeId, str_repeat('a', 64), $manifest, hash('sha256', $manifest), $this->actors[0]]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_annual_settlement_outcomes
                (supplier_id, employee_id, tax_year, annual_revision_id,
                 outcome, tax_difference_minor, bonus_difference_minor,
                 settlement_difference_minor, payable_minor,
                 payout_threshold_minor, settled_on, created_by)
             VALUES (?, ?, 2025, ?, "overpayment", ?, 0, ?, ?, 5000, ?, ?)',
        )->execute([$this->supplierId, $employeeId, $revisionId, $payableMinor, $payableMinor, $payableMinor, $settledOn, $this->actors[0]]);
    }

    private function child(int $employeeId, string $name, string $birthDate, int $order): void
    {
        $dependants = $this->container->get(PayrollDependantAction::class);
        self::assertInstanceOf(PayrollDependantAction::class, $dependants);
        $birthNumber = self::syntheticBirthNumber($birthDate, 'male', 200 + $order);
        [$given, $family] = explode(' ', $name, 2);
        $created = $dependants->create(
            $this->request('POST', "/api/payroll/people/{$employeeId}/dependants")->withParsedBody([
                'relation' => 'child_own',
                'full_name' => $name,
                'given_name' => $given,
                'family_name' => $family,
                'birth_date' => $birthDate,
                'birth_number' => substr($birthNumber, 0, 6) . '/' . substr($birthNumber, 6),
                'ztp_p' => false,
                'student' => false,
                'existence_from' => $birthDate,
                'existence_to' => null,
                'note' => null,
            ]),
            new Response(),
            ['id' => (string) $employeeId],
        );
        self::assertSame(200, $created->getStatusCode(), (string) $created->getBody());
        $dependantId = null;
        foreach ($this->json($created)['dependants'] as $dependant) {
            if ($dependant['full_name'] === $name) {
                $dependantId = (int) $dependant['id'];
            }
        }
        self::assertNotNull($dependantId);
        $claim = $dependants->createClaim(
            $this->request('POST', "/api/payroll/people/{$employeeId}/dependants/{$dependantId}/claims")->withParsedBody([
                'child_order' => $order,
                'claim_reason' => 'own_household',
                'evidence_status' => 'verified',
                'evidence_reference' => 'document:child-claim',
                'shared_household_confirmed' => true,
                'other_claimant_excluded' => true,
                'other_household_caregiver_status' => 'none',
                'ztp_p' => false,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ]),
            new Response(),
            ['id' => (string) $employeeId, 'dependantId' => (string) $dependantId],
        );
        self::assertSame(200, $claim->getStatusCode(), (string) $claim->getBody());
    }
}
