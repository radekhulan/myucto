<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollImporter;
use MyInvoice\Service\Payroll\PayrollPredecessorObligationScope;
use MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaPayroll;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převod mezd z `91_mzdy.xml` do mezd izolované firmy: osoby a vztahy, měsíční dávky
 * importu, opakovaný převod bez duplicit, zkouška nanečisto bez stop a kontrola před
 * převodem. Transakce se v tearDown vrací.
 */
#[Group('integration')]
final class PohodaPayrollImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PohodaPayrollImporter $importer;
    private int $userId = 0;
    private int $sourceSupplierId = 0;
    private string $tmp = '';

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(PohodaPayrollImporter::class);
        foreach (['payroll_attendance_imports', 'payroll_employees', 'payroll_offices', 'pohoda_import_map'] as $table) {
            if (!$this->db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $pdo = $this->db->pdo();
        $this->sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($this->sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_payroll_int_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testImportCreatesPersonsAndMonthsAndIsIdempotent(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = SyntheticPohodaPayroll::write($this->tmp);

        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $counts = self::stepCounts($protocol, PohodaPayrollImporter::STEP_MONTHS);
        self::assertSame(2, $counts['months'] ?? 0, $this->explain($protocol));
        self::assertSame(4, $counts['payslips'] ?? 0);
        self::assertSame(2, $counts['persons_created'] ?? 0, $this->explain($protocol));
        self::assertArrayNotHasKey('persons_failed', $counts, $this->explain($protocol));
        $codes = array_column(array_merge(...array_column($protocol->toArray()['steps'], 'messages')), 'code');
        self::assertContains('person_data_omitted', $codes, $this->explain($protocol));
        self::assertSame(2, $this->rows('payroll_employees', $supplierId));
        $batches = $this->db->pdo()->prepare('SELECT * FROM payroll_attendance_imports WHERE supplier_id = ?');
        $batches->execute([$supplierId]);
        self::assertSame(2, $this->rows('payroll_attendance_imports', $supplierId), json_encode($batches->fetchAll(\PDO::FETCH_ASSOC)) . $this->explain($protocol));
        $map = (new PohodaImportRepository($this->db))->all($supplierId, PohodaImportRepository::KIND_PAYROLL_MONTH);
        self::assertCount(2, $map, json_encode($map) . $this->explain($protocol));

        $again = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame(2, self::stepCounts($again, PohodaPayrollImporter::STEP_MONTHS)['existing'] ?? 0);
        self::assertSame(2, $this->rows('payroll_employees', $supplierId));
        self::assertSame(2, $this->rows('payroll_attendance_imports', $supplierId));
    }

    public function testPeopleDetailsIdentifiersTerminationDeadlinesAndOpenings(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = SyntheticPohodaPayroll::write($this->tmp);
        $pdo = $this->db->pdo();

        // Bez potvrzení původu se OIČ a ID PPV nepřevezmou, zbytek údajů ano.
        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $counts = self::stepCounts($protocol, PohodaPayrollImporter::STEP_PEOPLE);
        self::assertSame(2, $counts['identifiers_unconfirmed'] ?? 0, $this->explain($protocol));
        self::assertSame(2, $counts['person_card'] ?? 0, $this->explain($protocol));
        self::assertSame(2, $counts['tax_residence'] ?? 0, $this->explain($protocol));
        self::assertSame(2, $counts['tax_declarations'] ?? 0, $this->explain($protocol));
        self::assertSame(1, $counts['ended'] ?? 0, $this->explain($protocol));
        self::assertArrayNotHasKey('people_failed', $counts, $this->explain($protocol));
        self::assertSame(0, $this->rows('payroll_person_external_ids', $supplierId));

        $jana = $this->employment($supplierId, '1001');
        $petr = $this->employment($supplierId, '1002');
        self::assertSame('ended', $petr['status']);
        self::assertSame(SyntheticPohodaPayroll::PETR_END, $petr['end_date']);

        $identity = $pdo->prepare(
            'SELECT citizenship_country_code, birth_place, title_prefix FROM payroll_person_identity_history WHERE supplier_id = ? AND employee_id = ?'
        );
        $identity->execute([$supplierId, $jana['employee_id']]);
        self::assertSame(['citizenship_country_code' => 'CZ', 'birth_place' => 'Brno', 'title_prefix' => 'Ing.'], $identity->fetch(\PDO::FETCH_ASSOC));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_addresses WHERE supplier_id = ? AND employee_id = ? AND address_type = 'residence' AND city = 'Brno'", [$supplierId, $jana['employee_id']]));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_contacts WHERE supplier_id = ? AND employee_id = ? AND contact_type = 'email' AND is_primary = 1", [$supplierId, $jana['employee_id']]));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_contacts WHERE supplier_id = ? AND employee_id = ? AND contact_type = 'phone' AND is_primary = 1", [$supplierId, $petr['employee_id']]));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_tax_residences WHERE supplier_id = ? AND employee_id = ? AND residence = 'czech-resident'", [$supplierId, $jana['employee_id']]));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_tax_declarations WHERE supplier_id = ? AND employee_id = ? AND status = 'signed' AND effective_from = '2026-01-01'", [$supplierId, $jana['employee_id']]));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_tax_declarations WHERE supplier_id = ? AND employee_id = ? AND status = 'not-signed'", [$supplierId, $petr['employee_id']]));
        $terms = $pdo->prepare('SELECT cz_isco_code, jmhz_workplace_municipality_code, work_place FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ? ORDER BY effective_from DESC LIMIT 1');
        $terms->execute([$supplierId, $jana['id']]);
        self::assertSame(['cz_isco_code' => '43111', 'jmhz_workplace_municipality_code' => '582786', 'work_place' => 'Brno'], $terms->fetch(\PDO::FETCH_ASSOC), $this->explain($protocol));

        // Zákonné termíny. Jana nastoupila před začátkem vedení mezd v MyÚčtu,
        // nástup vyřídila PAMICA: povinnost s rozhodnou událostí před startem
        // se nezakládá a nic nečeká na obsluhu.
        self::assertSame([], $this->checklist($supplierId, (int) $jana['id'], 'onboarding'), $this->explain($protocol));
        self::assertNotContains('pending', $this->checklist($supplierId, (int) $jana['id'], 'change'), $this->explain($protocol));
        self::assertSame(0, $this->scalar(
            "SELECT COUNT(*) FROM payroll_employment_checklist_items item
              WHERE item.supplier_id = ? AND item.employment_id = ? AND item.status = 'pending'
                AND NOT " . PayrollPredecessorObligationScope::sql('item'),
            [$supplierId, (int) $jana['id']],
        ), $this->explain($protocol));
        // Petr nastoupil i skončil za MyÚčta: odškrtnuté jen to, k čemu PAMICA nese doklad.
        self::assertSame([
            'employment_contract' => 'completed',
            'health_insurance_registration' => 'pending',
            'social_jmhz_registration' => 'pending',
            'tax_declaration' => 'pending',
        ], $this->checklist($supplierId, (int) $petr['id'], 'onboarding'));
        $offboarding = $this->checklist($supplierId, (int) $petr['id'], 'offboarding');
        self::assertSame('completed', $offboarding['termination_document'] ?? null, json_encode($offboarding) . $this->explain($protocol));
        self::assertSame('completed', $offboarding['health_insurance_deregistration'] ?? null);
        self::assertSame('completed', $offboarding['social_jmhz_deregistration'] ?? null);

        // Sjednaná měsíční mzda z PAMICA: od ledna 40 000 Kč, od února 42 000 Kč.
        $wages = $pdo->prepare('SELECT effective_from, monthly_gross_minor FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ? ORDER BY effective_from');
        $wages->execute([$supplierId, $jana['id']]);
        self::assertSame([
            ['effective_from' => '2025-03-01', 'monthly_gross_minor' => 4000000],
            ['effective_from' => '2026-02-01', 'monthly_gross_minor' => 4200000],
        ], array_map(static fn (array $r): array => ['effective_from' => $r['effective_from'], 'monthly_gross_minor' => (int) $r['monthly_gross_minor']], $wages->fetchAll(\PDO::FETCH_ASSOC)), $this->explain($protocol));

        // Pracoviště a CZ-ISCO musí nést KAŽDÁ verze podmínek, ne jen poslední. Opravit jde
        // vždy jen poslední verzi, takže zapsáno až po všech měsících by starší verze zůstaly
        // prázdné a za jejich měsíce by nešlo zmrazit hlášení JMHZ.
        $places = $pdo->prepare(
            'SELECT effective_from, jmhz_workplace_municipality_code AS obec, work_place, cz_isco_code
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY effective_from'
        );
        $places->execute([$supplierId, $jana['id']]);
        self::assertSame([
            ['effective_from' => '2025-03-01', 'obec' => '582786', 'work_place' => 'Brno', 'cz_isco_code' => '43111'],
            ['effective_from' => '2026-02-01', 'obec' => '582786', 'work_place' => 'Brno', 'cz_isco_code' => '43111'],
        ], array_map(static fn (array $r): array => [
            'effective_from' => $r['effective_from'],
            'obec' => $r['obec'],
            'work_place' => $r['work_place'],
            'cz_isco_code' => $r['cz_isco_code'],
        ], $places->fetchAll(\PDO::FETCH_ASSOC)), $this->explain($protocol));

        // Průměrný výdělek čtvrtletí, nepřítomnost s daty, výplatní účet a účet pojišťovny.
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_average_earning_snapshots WHERE supplier_id = ? AND employment_id = ? AND status = 'approved' AND average_hourly_minor = 25000", [$supplierId, $jana['id']]), $this->explain($protocol));
        // Dovolenou nese souhrn z importu docházky, proto ji převod nezakládá podruhé.
        // Měsíc se souhrnem a zároveň nepřítomností s daty by tutéž dobu vedl dvakrát
        // a krácení měsíční mzdy by se neprovedlo vůbec.
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM payroll_absences WHERE supplier_id = ? AND employment_id = ? AND absence_type = 'vacation'", [$supplierId, $jana['id']]), $this->explain($protocol));
        self::assertSame(1, self::stepCounts($protocol, PohodaPayrollImporter::STEP_PEOPLE)['absences_from_import'] ?? 0, $this->explain($protocol));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM payroll_person_accounts WHERE supplier_id = ? AND employee_id = ? AND is_active = 1', [$supplierId, $jana['employee_id']]));
        // Účet, na který PAMICA opakovaně vyplácela mzdu, je ověřený dnem POSLEDNÍ výplaty
        // (10. 3. za únor), ne dnem převodu. Bez ověření brání značka podání i příkazu.
        self::assertSame(1, $this->scalar(
            "SELECT COUNT(*) FROM payroll_person_accounts
              WHERE supplier_id = ? AND employee_id = ? AND verification_source = 'user_verified'
                AND verified_on = '2026-03-10' AND verified_by = ?",
            [$supplierId, $jana['employee_id'], $this->userId],
        ), $this->explain($protocol));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_employee_profiles WHERE supplier_id = ? AND employee_id = ? AND payout_method = 'bank'", [$supplierId, $jana['employee_id']]));
        // Předpis základní měsíční mzdy dostane jen měsíčně placený vztah, ne Petrova DPP za hodiny.
        // Souvislá řada bez děr: první předpis platí od prvního převáděného měsíce, druhý
        // od měsíce, kdy PAMICA mzdu zvedla, a předchozí končí dnem předtím.
        $prescriptions = $pdo->prepare(
            "SELECT r.valid_from, r.valid_to, r.amount_minor, r.allocation_rule
               FROM payroll_recurring_components r
               JOIN payroll_component_definitions c ON c.id = r.component_id
              WHERE r.supplier_id = ? AND r.employment_id = ? AND c.code = 'MZDA_MESICNI'
              ORDER BY r.valid_from"
        );
        $prescriptions->execute([$supplierId, $jana['id']]);
        self::assertSame([
            ['valid_from' => '2026-01-01', 'valid_to' => '2026-01-31', 'amount_minor' => 4000000, 'allocation_rule' => 'calendar_days'],
            ['valid_from' => '2026-02-01', 'valid_to' => null, 'amount_minor' => 4200000, 'allocation_rule' => 'calendar_days'],
        ], array_map(static fn (array $r): array => [
            'valid_from' => $r['valid_from'],
            'valid_to' => $r['valid_to'],
            'amount_minor' => (int) $r['amount_minor'],
            'allocation_rule' => $r['allocation_rule'],
        ], $prescriptions->fetchAll(\PDO::FETCH_ASSOC)), $this->explain($protocol));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_recurring_components WHERE supplier_id = ? AND employment_id = ?', [$supplierId, $petr['id']]));
        // Rodičovská dovolená z PAMICA (H08) se přenese jako absence, i když hodiny nenese.
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_absences WHERE supplier_id = ? AND employment_id = ? AND absence_type = 'parental' AND date_from = '2026-02-01' AND date_to = '2026-02-28'", [$supplierId, $petr['id']]), $this->explain($protocol));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_institution_accounts a JOIN payroll_institutions i ON i.id = a.institution_id WHERE a.supplier_id = ? AND i.institution_type = 'health_insurer' AND i.institution_code = '111' AND a.variable_symbol = '12345678'", [$supplierId]), $this->explain($protocol));

        // Dítě s daňovým zvýhodněním na 1. dítě; sleva na poplatníka (kód 36) dítětem není.
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM payroll_dependants WHERE supplier_id = ? AND employee_id = ?', [$supplierId, $jana['employee_id']]));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_tax_child_claims WHERE supplier_id = ? AND employee_id = ? AND child_order = 1 AND evidence_status = 'verified' AND effective_from = '2026-01-01'", [$supplierId, $jana['employee_id']]), $this->explain($protocol));

        // Příslušnost k sociálnímu pojištění a sleva pracujícího důchodce po měsících.
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM payroll_person_social_jurisdictions WHERE supplier_id = ? AND jurisdiction = 'czech_regime_verified' AND a1_status = 'not_applicable'", [$supplierId]));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM payroll_person_social_discount_claims WHERE supplier_id = ? AND employee_id = ? AND status = 'not_claimed'", [$supplierId, $jana['employee_id']]));
        $discounts = $pdo->prepare('SELECT status, effective_from, effective_to FROM payroll_person_social_discount_claims WHERE supplier_id = ? AND employee_id = ? ORDER BY effective_from');
        $discounts->execute([$supplierId, $petr['employee_id']]);
        self::assertSame([
            ['status' => 'not_claimed', 'effective_from' => '2026-01-01', 'effective_to' => '2026-01-31'],
            ['status' => 'verified', 'effective_from' => '2026-02-01', 'effective_to' => null],
        ], $discounts->fetchAll(\PDO::FETCH_ASSOC));

        // S potvrzením se uloží platné OIČ a ID PPV; OIČ s chybnou kontrolní číslicí ne.
        $again = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, null, null, null, true, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $counts = self::stepCounts($again, PohodaPayrollImporter::STEP_PEOPLE);
        self::assertSame(1, $counts['oic'] ?? 0, $this->explain($again));
        self::assertSame(2, $counts['id_ppv'] ?? 0, $this->explain($again));
        self::assertSame(1, $counts['oic_invalid'] ?? 0, $this->explain($again));
        self::assertArrayNotHasKey('person_card', $counts, 'Opakovaný převod nesmí kartu osoby zapisovat znovu.');
        self::assertSame(1, $this->rows('payroll_person_external_ids', $supplierId));
        self::assertSame(2, $this->rows('payroll_employment_external_ids', $supplierId));

        // Mzdy vedené v MyÚčtu od února: leden z PAMICA jako počáteční stav kumulací.
        $pdo->prepare("UPDATE payroll_module_state SET start_period = '2026-02-01' WHERE supplier_id = ?")->execute([$supplierId]);
        $third = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, null, null, null, true, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertSame(2, self::stepCounts($third, PohodaPayrollImporter::STEP_PEOPLE)['openings'] ?? 0, $this->explain($third));
        $opening = $pdo->prepare("SELECT values_json FROM payroll_statutory_accumulator_openings WHERE supplier_id = ? AND employee_id = ? AND calculation_kind = 'social_insurance'");
        $opening->execute([$supplierId, $jana['employee_id']]);
        self::assertSame(4300000, json_decode((string) $opening->fetchColumn(), true)['assessment_base_minor_units'] ?? null);
        $tax = $pdo->prepare("SELECT values_json FROM payroll_statutory_accumulator_openings WHERE supplier_id = ? AND employee_id = ? AND calculation_kind = 'income_tax'");
        $tax->execute([$supplierId, $jana['employee_id']]);
        $values = json_decode((string) $tax->fetchColumn(), true);
        self::assertSame(1, $values['completed_months'] ?? null);
        self::assertSame(388000, $values['advance_tax_minor_units'] ?? null);
        self::assertSame(257000, $values['applied_non_refundable_credits_minor_units'] ?? null);
        $tax->execute([$supplierId, $petr['employee_id']]);
        self::assertSame(75000, json_decode((string) $tax->fetchColumn(), true)['withholding_tax_minor_units'] ?? null);

        $fourth = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, null, null, null, true, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertSame(2, self::stepCounts($fourth, PohodaPayrollImporter::STEP_PEOPLE)['openings_existing'] ?? 0, $this->explain($fourth));
        // Dvě osoby krát tři druhy kumulace (sociální, zdravotní, daň z příjmů).
        self::assertSame(6, $this->rows('payroll_statutory_accumulator_openings', $supplierId));

        // Oprava lednové mzdy v PAMICA: opakovaný převod srovná vlastní počáteční stavy
        // převodu (Jana), nezměněné nechá (Petr).
        $changedDir = $this->tmp . '/changed/' . basename(dirname($file));
        mkdir($changedDir, 0755, true);
        $changed = $changedDir . '/' . basename($file);
        file_put_contents($changed, preg_replace('~<KcSocZak>43000</KcSocZak>~', '<KcSocZak>44000</KcSocZak>', (string) file_get_contents($file), 1));
        $fifth = $this->importer->run($supplierId, $this->userId, $changed, SyntheticPohodaPayroll::YEAR, false, null, null, null, true, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($fifth->hasErrors(), $this->explain($fifth));
        $counts = self::stepCounts($fifth, PohodaPayrollImporter::STEP_PEOPLE);
        self::assertSame([1, 1], [$counts['openings'] ?? 0, $counts['openings_existing'] ?? 0], $this->explain($fifth));
        $latest = $pdo->prepare("SELECT values_json FROM payroll_statutory_accumulator_openings WHERE supplier_id = ? AND employee_id = ? AND calculation_kind = 'social_insurance' ORDER BY id DESC LIMIT 1");
        $latest->execute([$supplierId, $jana['employee_id']]);
        self::assertSame(4400000, json_decode((string) $latest->fetchColumn(), true)['assessment_base_minor_units'] ?? null);
    }

    /**
     * Regrese: účty, které založil starší běh převodu (ten ověřovat ještě neuměl), musí
     * opakovaný převod doplnit. Dřív krok skončil holým `return []`, jakmile osoba nějaký
     * účet měla — ověření se proto nedoplnilo NIKDY a opakovaný import, kterým to jde
     * přirozeně zkusit, nechal evidenci přesně tak, jak byla. Ověřený účet je přitom
     * podmínka bankovního příkazu (mezera `payout_account`).
     */
    public function testRepeatedImportVerifiesAccountsLeftUnverifiedByEarlierRun(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = SyntheticPohodaPayroll::write($this->tmp);

        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $verified = $this->scalar(
            'SELECT COUNT(*) FROM payroll_person_accounts WHERE supplier_id = ? AND verification_source IS NOT NULL',
            [$supplierId],
        );
        self::assertGreaterThan(0, $verified, $this->explain($protocol));

        // Stav instalace po starším převodu: účty jsou založené, ověření u nich chybí.
        $this->db->pdo()
            ->prepare('UPDATE payroll_person_accounts SET verification_source = NULL, verified_on = NULL, verified_by = NULL WHERE supplier_id = ?')
            ->execute([$supplierId]);
        self::assertSame(0, $this->scalar(
            'SELECT COUNT(*) FROM payroll_person_accounts WHERE supplier_id = ? AND verification_source IS NOT NULL',
            [$supplierId],
        ));

        $again = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($verified, $this->scalar(
            'SELECT COUNT(*) FROM payroll_person_accounts WHERE supplier_id = ? AND verification_source IS NOT NULL',
            [$supplierId],
        ), $this->explain($again));
        // Datum nese den poslední výplaty z PAMICA, ne den opakovaného převodu.
        self::assertSame(1, $this->scalar(
            "SELECT COUNT(*) FROM payroll_person_accounts
              WHERE supplier_id = ? AND verification_source = 'user_verified'
                AND verified_on = '2026-03-10' AND verified_by = ?",
            [$supplierId, $this->userId],
        ), $this->explain($again));
    }

    /**
     * Výplata na účet celým podílem nesmí mít zároveň 100 % v hotovosti. Nová karta
     * z převodu má hotovost 0; kartu, kterou tak nechal starší převod, opakovaný převod
     * opraví a řekne to. Jinak nastavené rozdělení (vědomá volba) nechá být.
     */
    public function testBankPayoutGetsZeroCashShareAndOldCardsAreRepaired(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = SyntheticPohodaPayroll::write($this->tmp);
        $cashShares = fn (): array => array_map('intval', $this->db->pdo()->query(
            'SELECT cash_allocation_basis_points FROM payroll_employee_profiles WHERE supplier_id = ' . $supplierId
            . ' AND payout_method = "bank" ORDER BY employee_id'
        )->fetchAll(\PDO::FETCH_COLUMN));

        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $shares = $cashShares();
        self::assertNotSame([], $shares, $this->explain($protocol));
        self::assertSame(array_fill(0, count($shares), 0), $shares);

        // Stav po starším převodu: na účet celým podílem a k tomu hotovost 100 %.
        $this->db->pdo()->prepare(
            'UPDATE payroll_employee_profiles SET cash_allocation_basis_points = 10000 WHERE supplier_id = ? AND payout_method = "bank"'
        )->execute([$supplierId]);
        $again = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame(array_fill(0, count($shares), 0), $cashShares(), $this->explain($again));
        self::assertSame(count($shares), self::stepCounts($again, PohodaPayrollImporter::STEP_PEOPLE)['payout_cash_share_repaired'] ?? 0);
    }

    /**
     * Měsíc s nemocí musí po převodu jít schválit. Nemoc, ošetřovné, otcovská, neplacené
     * volno a neomluvená absence rozhodují o náhradě mzdy i vyloučené době, takže je
     * evidence vede jedině s daty od a do: dokud šly hodiny měsíčním souhrnem z importu
     * docházky, schválení je vracelo s `absence_hours_without_dates` a mzdový běh se o ty
     * měsíce zastavil. Nepřítomnost s daty proto zapisuje převod z `MZneprit` a tytéž
     * hodiny do souhrnu nejdou - jeden údaj, jeden zdroj.
     */
    public function testDatedSicknessIsWrittenOnceAndItsMonthCanBeApproved(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = $this->writeSicknessPayroll();

        $protocol = $this->importer->run($supplierId, $this->userId, $file, 2026, false, null, null, null, false, true, PohodaPayrollImporter::START_KEEP);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $employment = $this->employment($supplierId, '5001');

        // Nepřítomnost s daty přesně z `MZneprit`, nic dopočítaného.
        self::assertSame(1, $this->scalar(
            "SELECT COUNT(*) FROM payroll_absences
              WHERE supplier_id = ? AND employment_id = ? AND absence_type = 'dpn'
                AND date_from = '2026-03-05' AND date_to = '2026-03-13'",
            [$supplierId, $employment['id']],
        ), $this->explain($protocol));

        // Měsíc je schválený, takže se o něj mzdový běh nezastaví.
        self::assertSame('approved', (string) $this->db->pdo()->query(sprintf(
            "SELECT status FROM payroll_time_months WHERE supplier_id = %d AND employment_id = %d AND period_start = '2026-03-01'",
            $supplierId,
            (int) $employment['id'],
        ))?->fetchColumn(), $this->explain($protocol));

        // Tytéž hodiny nesmí být zároveň v souhrnu z importu, jinak by se doba vedla dvakrát.
        $summary = $this->db->pdo()->prepare(
            'SELECT values_json FROM payroll_time_month_import_summaries
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?'
        );
        $summary->execute([$supplierId, $employment['id'], '2026-03-01']);
        /** @var array<string,int> $values */
        $values = json_decode((string) $summary->fetchColumn(), true) ?: [];
        self::assertArrayNotHasKey('sick_hours', $values, json_encode($values));
        self::assertSame([], PayrollJmhzWorkMonthSummaryBuilder::importHoursRequiringDates($values));

        // Měsíc počítá MyÚčto: náhrada mzdy při DPN má zmrazený výpočet a schválený vstup,
        // jinak by ji běh nevyplatil a krácení měsíční mzdy by skončilo v ruční kontrole.
        self::assertSame(1, self::stepCounts($protocol, PohodaPayrollImporter::STEP_SICKNESS)['sickness_compensations'] ?? 0, $this->explain($protocol));
        $event = $this->db->pdo()->prepare(
            "SELECT event.first_day_fully_worked, event.compensation_minor FROM payroll_sickness_events event
               JOIN payroll_absences absence ON absence.id = event.absence_id
              WHERE absence.supplier_id = ? AND absence.employment_id = ?"
        );
        $event->execute([$supplierId, $employment['id']]);
        $sickness = $event->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($sickness, $this->explain($protocol));
        self::assertSame(0, (int) $sickness['first_day_fully_worked']);
        $input = "SELECT SUM(i.amount_minor) FROM payroll_inputs i JOIN payroll_component_definitions c ON c.id = i.component_id
                   WHERE i.supplier_id = ? AND i.employment_id = ? AND c.code = 'NAHRADA_MZDY_DPN' AND i.status = 'approved' AND i.period_start = '2026-03-01'";
        self::assertSame((int) $sickness['compensation_minor'], $this->scalar($input, [$supplierId, $employment['id']]));
        self::assertGreaterThan(0, (int) $sickness['compensation_minor']);

        // Opakovaný převod výpočet nezdvojí.
        $again = $this->importer->run($supplierId, $this->userId, $file, 2026, false, null, null, null, false, true, PohodaPayrollImporter::START_KEEP);
        self::assertArrayNotHasKey('sickness_compensations', self::stepCounts($again, PohodaPayrollImporter::STEP_SICKNESS), $this->explain($again));
    }

    /**
     * Převod PAMICA po letech: nepřítomnosti dalšího roku se zapíšou i u vztahu, který už
     * nepřítomnost z dřívějšího roku má. Dřív se takový vztah přeskočil celý. Neschopnost
     * přes konec roku navazuje na okno náhrady mzdy a opakovaný převod nic nezdvojí.
     */
    public function testAbsencesOfNextYearAreWrittenForRelationWithEarlierAbsence(): void
    {
        $supplierId = $this->payrollSupplier();
        // Mzdy vede MyÚčto až od roku 2027: oba převáděné roky jsou evidence předchozího programu.
        $this->db->pdo()->prepare("UPDATE payroll_module_state SET start_period = '2027-01-01' WHERE supplier_id = ?")->execute([$supplierId]);
        $file = $this->writeTwoYearSicknessPayroll();

        foreach ([2025, 2026, 2026] as $year) {
            $protocol = $this->importer->run($supplierId, $this->userId, $file, $year, false, null, null, null, false, true, PohodaPayrollImporter::START_KEEP);
            self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        }
        $employment = $this->employment($supplierId, '5001');
        $stmt = $this->db->pdo()->prepare(
            "SELECT date_from, date_to, sickness_window_carried_days FROM payroll_absences
              WHERE supplier_id = ? AND employment_id = ? AND absence_type = 'dpn' AND status NOT IN ('cancelled', 'rejected') ORDER BY date_from"
        );
        $stmt->execute([$supplierId, $employment['id']]);
        self::assertSame([
            ['2025-12-20', '2025-12-31', 0],
            ['2026-01-01', '2026-01-10', 12],
        ], array_map(static fn (array $r): array => [$r['date_from'], $r['date_to'], (int) $r['sickness_window_carried_days']], $stmt->fetchAll(\PDO::FETCH_ASSOC)),
            $this->explain($protocol));
        self::assertSame(1, self::stepCounts($protocol, PohodaPayrollImporter::STEP_PEOPLE)['absences_existing'] ?? 0, $this->explain($protocol));
    }

    /**
     * Osoba s neschopností od 20. 12. 2025 do 10. 1. 2026, kterou PAMICA vede po měsících
     * (prosincová a lednová mzda). Syntetická data, žádné reálné doklady ani osoby.
     */
    private function writeTwoYearSicknessPayroll(): string
    {
        $dir = $this->tmp . '/12345678_2026_2y';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $x = '';
        $row = static function (string $table, array $cols) use (&$x): void {
            $x .= "<{$table}>";
            foreach ($cols as $k => $v) {
                $x .= "<{$k}>" . htmlspecialchars((string) $v, ENT_XML1) . "</{$k}>";
            }
            $x .= "</{$table}>";
        };
        $row('sMZneprit', ['ID' => 1, 'Cislo' => 'H01', 'Nazev' => 'Náhrada za nemoc']);
        $row('sMZslozky', ['ID' => 1, 'Cislo' => 'M01', 'Nazev' => 'Základní mzda měsíční']);
        $row('sMzPoj', ['ID' => 1, 'IDS' => 'VZP', 'Kod' => '111']);
        $row('ZAM', ['ID' => 1, 'OsCislo' => '5001', 'Jmeno' => 'Hana', 'Prijmeni' => 'Nemocná', 'DatNar' => '1988-02-03',
            'StatPris' => 'CZ', 'Nerezident' => 0, 'RefPoj' => 1, 'Ulice' => 'Zkušební', 'CP' => '1', 'Obec' => 'Brno',
            'PSC' => '60200', 'Stat' => 'CZ']);
        $row('ZAMpomer', ['ID' => 1, 'RefZAM' => 1, 'Poradi' => 1, 'Cislo' => '1', 'JeDPP' => 0, 'DatNast' => '2024-01-01', 'TUvazek' => 40]);
        foreach ([[30, 2025, 12, '2026-01-10', '2025-12-20', '2025-12-31', 64], [31, 2026, 1, '2026-02-10', '2026-01-01', '2026-01-10', 56]] as $i => [$id, $year, $month, $paid, $from, $to, $hours]) {
            $row('MZ', ['ID' => $id, 'RefZAM' => 1, 'RefPomer' => 1, 'Rok' => $year, 'RelMes' => $month, 'HodFond' => 176, 'DnyFond2' => 22,
                'TUvazek' => 40, 'HodOdpra' => 176 - $hours, 'RefPoj' => 1, 'KcHrubaM' => 35000, 'KcCistaM' => 27000, 'Prohlas' => 1,
                'JeSocPP' => 1, 'KcSoc' => 2485, 'KcZaklM' => 35000, 'DnyPrac' => 22, 'DnyOdpra' => 14, 'KcPrum' => 200,
                'Datum' => $paid, 'KcVyplat' => 27000]);
            $row('MZslozky', ['ID' => $i + 1, 'RefAg' => $id, 'RefSlozka' => 1, 'KcMzda' => 35000, 'Hodnota1' => 35000]);
            $row('MZneprit', ['ID' => $i + 1, 'RefAg' => $id, 'RefSlozka' => 1, 'HodPrac' => $hours, 'KcNahr' => 3000,
                'DatZac' => $from, 'DatKon' => $to]);
        }

        $file = $dir . '/91_mzdy.xml';
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="12345678" year="2026" source="POHODA" state="ok">' . $x . '</mdbExport>');
        return $file;
    }

    /**
     * Jedna fiktivní osoba s měsíční mzdou a nemocí, kterou PAMICA nese s datem od a do.
     * Syntetická data, žádné reálné doklady ani osoby.
     */
    private function writeSicknessPayroll(): string
    {
        $dir = $this->tmp . '/12345678_2026';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $x = '';
        $row = static function (string $table, array $cols) use (&$x): void {
            $x .= "<{$table}>";
            foreach ($cols as $k => $v) {
                $x .= "<{$k}>" . htmlspecialchars((string) $v, ENT_XML1) . "</{$k}>";
            }
            $x .= "</{$table}>";
        };
        $row('sMZneprit', ['ID' => 1, 'Cislo' => 'H01', 'Nazev' => 'Náhrada za nemoc']);
        $row('sMZslozky', ['ID' => 1, 'Cislo' => 'M01', 'Nazev' => 'Základní mzda měsíční']);
        $row('sMzPoj', ['ID' => 1, 'IDS' => 'VZP', 'Kod' => '111']);
        $row('ZAM', ['ID' => 1, 'OsCislo' => '5001', 'Jmeno' => 'Hana', 'Prijmeni' => 'Nemocná', 'DatNar' => '1988-02-03',
            'StatPris' => 'CZ', 'Nerezident' => 0, 'RefPoj' => 1, 'Ulice' => 'Zkušební', 'CP' => '1', 'Obec' => 'Brno',
            'PSC' => '60200', 'Stat' => 'CZ']);
        $row('ZAMpomer', ['ID' => 1, 'RefZAM' => 1, 'Poradi' => 1, 'Cislo' => '1', 'JeDPP' => 0, 'DatNast' => '2024-01-01', 'TUvazek' => 40]);
        $row('MZ', ['ID' => 30, 'RefZAM' => 1, 'RefPomer' => 1, 'Rok' => 2026, 'RelMes' => 3, 'HodFond' => 176, 'DnyFond2' => 22,
            'TUvazek' => 40, 'HodOdpra' => 136, 'RefPoj' => 1, 'KcHrubaM' => 35000, 'KcCistaM' => 27000, 'Prohlas' => 1,
            'JeSocPP' => 1, 'KcSoc' => 2485, 'KcZaklM' => 35000, 'DnyPrac' => 22, 'DnyOdpra' => 17, 'KcPrum' => 200,
            'Datum' => '2026-04-10', 'KcVyplat' => 27000]);
        $row('MZslozky', ['ID' => 1, 'RefAg' => 30, 'RefSlozka' => 1, 'KcMzda' => 35000, 'Hodnota1' => 35000]);
        $row('MZneprit', ['ID' => 1, 'RefAg' => 30, 'RefSlozka' => 1, 'HodPrac' => 40, 'KcNahr' => 6000,
            'DatZac' => '2026-03-05', 'DatKon' => '2026-03-13']);

        $file = $dir . '/91_mzdy.xml';
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="12345678" year="2026" source="POHODA" state="ok">' . $x . '</mdbExport>');
        return $file;
    }

    public function testDryRunLeavesNothingBehind(): void
    {
        $supplierId = $this->payrollSupplier();
        $protocol = $this->importer->run($supplierId, $this->userId, SyntheticPohodaPayroll::write($this->tmp), SyntheticPohodaPayroll::YEAR, true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(2, self::stepCounts($protocol, PohodaPayrollImporter::STEP_MONTHS)['months'] ?? 0);
        self::assertSame(0, $this->rows('payroll_employees', $supplierId));
        self::assertSame(0, $this->rows('payroll_attendance_imports', $supplierId));
        self::assertSame(0, $this->rows('pohoda_import_map', $supplierId));
        self::assertTrue($this->db->pdo()->inTransaction(), 'Zkouška nanečisto nesmí vrátit vnější transakci.');
    }

    /**
     * Firma bez mezd: kontrola před převodem oznámí, že je převod zapne, a převod pak
     * modul zapne, založí účtárnu, nastaví začátek za posledním měsícem exportu a mzdy
     * převezme. Existující nastavení při opakování nepřepíše.
     */
    public function testWithoutPayrollModuleTheImportEnablesIt(): void
    {
        $supplierId = $this->createIsolatedSupplier($this->db->pdo(), $this->sourceSupplierId);
        $this->db->pdo()->prepare('UPDATE supplier SET payroll_enabled = 0 WHERE id = ?')->execute([$supplierId]);
        $file = SyntheticPohodaPayroll::write($this->tmp);

        $codes = array_column($this->importer->preflight($supplierId, $file, SyntheticPohodaPayroll::YEAR), 'code');
        self::assertContains('payroll_module_will_enable', $codes);
        self::assertContains('payroll_office_will_create', $codes);
        self::assertNotContains('payroll_disabled', $codes);
        self::assertNotContains('payroll_office_missing', $codes);
        self::assertContains('payroll_no_months', array_column($this->importer->preflight($supplierId, $file, 2025), 'code'));

        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertGreaterThan(0, $this->rows('payroll_employees', $supplierId), $this->explain($protocol));
        $stmt = $this->db->pdo()->prepare('SELECT s.payroll_enabled, m.status, m.start_period FROM supplier s
            JOIN payroll_module_state m ON m.supplier_id = s.id WHERE s.id = ?');
        $stmt->execute([$supplierId]);
        $state = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['1', 'setup', '2026-03-01'], [(string) $state['payroll_enabled'], (string) $state['status'], (string) $state['start_period']]);
        self::assertSame(1, $this->rows('payroll_offices', $supplierId));

        $again = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame(1, $this->rows('payroll_offices', $supplierId));
    }

    /**
     * Export uprostřed února nese i únorové mzdy, které v předchozím programu teprve
     * běží. Převod je nepřevezme ani jako měsíc, ani jako srovnávací úhrny, a začátek
     * vedení mezd nové firmy nastaví na únor — měsíc, který MyÚčto musí spočítat.
     */
    /**
     * Nerezident se státem rezidence v `ResCisSTOBC` dostane rezidenci převodem; bez státu
     * zůstává k ručnímu doplnění (dřív se ručně doplňoval vždy).
     */
    public function testNonResidentWithResidenceCountryIsTakenOver(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = SyntheticPohodaPayroll::write($this->tmp);
        file_put_contents($file, str_replace(
            '<StatPris>SK</StatPris><Nerezident>0</Nerezident>',
            '<StatPris>SK</StatPris><Nerezident>1</Nerezident><ResCisSTOBC>SK</ResCisSTOBC>',
            (string) file_get_contents($file),
        ));

        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $petr = $this->employment($supplierId, '1002');
        self::assertSame(1, $this->scalar(
            "SELECT COUNT(*) FROM payroll_person_tax_residences WHERE supplier_id = ? AND employee_id = ? AND residence = 'non-resident' AND country_code = 'SK'",
            [$supplierId, $petr['employee_id']],
        ), $this->explain($protocol));
    }

    public function testMonthStillRunningOnExportDayIsNotTakenOver(): void
    {
        $supplierId = $this->createIsolatedSupplier($this->db->pdo(), $this->sourceSupplierId);
        $this->db->pdo()->prepare('UPDATE supplier SET payroll_enabled = 0 WHERE id = ?')->execute([$supplierId]);
        $file = SyntheticPohodaPayroll::write($this->tmp);
        file_put_contents($file, str_replace('<mdbExport ', '<mdbExport created="2026-02-15T10:00:00" ', (string) file_get_contents($file)));

        $preflight = $this->importer->preflight($supplierId, $file, SyntheticPohodaPayroll::YEAR);
        self::assertContains('payroll_open_months', array_column($preflight, 'code'));

        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, self::stepCounts($protocol, PohodaPayrollImporter::STEP_MONTHS)['months'] ?? 0, $this->explain($protocol));
        self::assertSame(1, $this->rows('payroll_attendance_imports', $supplierId));
        self::assertSame(0, $this->scalar(
            "SELECT COUNT(*) FROM payroll_migration_reference_totals WHERE supplier_id = ? AND period_start = '2026-02-01'",
            [$supplierId],
        ));
        $start = $this->db->pdo()->prepare('SELECT start_period FROM payroll_module_state WHERE supplier_id = ?');
        $start->execute([$supplierId]);
        self::assertSame('2026-02-01', (string) $start->fetchColumn());
    }

    /**
     * Volba „docházku a vstupy rovnou schválit" musí být v protokolu vidět: u prvního
     * převodu hlásil protokol 0 schválených měsíců docházky (počítal je jen opakovaný
     * převod) a u opakovaného 0 všeho, protože všechno schválené už bylo.
     */
    public function testApproveOptionIsReportedOnFirstAndRepeatedRun(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = SyntheticPohodaPayroll::write($this->tmp);

        $first = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, null, null, null, false, true, PohodaPayrollImporter::START_KEEP);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        $counts = self::stepCounts($first, PohodaPayrollImporter::STEP_MONTHS);
        self::assertGreaterThan(0, ($counts['time_months_approved'] ?? 0) + ($counts['time_months_not_approved'] ?? 0), $this->explain($first));
        self::assertGreaterThan(0, $counts['inputs_approved'] ?? 0, $this->explain($first));

        $again = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, null, null, null, false, true, PohodaPayrollImporter::START_KEEP);
        $counts = self::stepCounts($again, PohodaPayrollImporter::STEP_MONTHS);
        self::assertSame(0, $counts['inputs_approved'] ?? 0, $this->explain($again));
        self::assertGreaterThan(0, $counts['inputs_already_approved'] ?? 0, $this->explain($again));
        self::assertSame(
            (int) (self::stepCounts($first, PohodaPayrollImporter::STEP_MONTHS)['time_months_approved'] ?? 0),
            (int) ($counts['time_months_already_approved'] ?? 0),
            $this->explain($again),
        );
    }

    /**
     * Převzaté měsíce nezakládají dohodu o srážkách za každý měsíc: na kartě jich
     * bylo tolik, kolik převedených měsíců. Měsíce, které MyÚčto počítá, je dál mají.
     */
    public function testTakenOverMonthsDoNotCreateMonthlyDeductionAgreements(): void
    {
        $attendanceAgreements = fn (int $supplierId): int => $this->scalar(
            "SELECT COUNT(*) FROM payroll_deduction_agreements WHERE supplier_id = ? AND agreement_reference LIKE 'attendance:%'",
            [$supplierId],
        );
        $file = SyntheticPohodaPayroll::write($this->tmp);

        $counted = $this->payrollSupplier();
        $this->importer->run($counted, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertGreaterThan(0, $attendanceAgreements($counted), 'Syntetický export musí nést srážku ze mzdy.');

        $takenOver = $this->payrollSupplier();
        $this->db->pdo()->prepare("UPDATE payroll_module_state SET start_period = '2026-03-01' WHERE supplier_id = ?")
            ->execute([$takenOver]);
        $protocol = $this->importer->run($takenOver, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(0, $attendanceAgreements($takenOver), $this->explain($protocol));
    }

    /**
     * Existující firma (po resetu zůstane konfigurace) má začátek vedení mezd dřív, než
     * končí měsíce zpracované PAMICA. Náhled to řekne a nabídne posun; ostrý převod bez
     * rozhodnutí se odmítne a nic nezapíše. S posunem převezme měsíce jako zpracované
     * předchozím programem: žádné dohody o srážkách ani vstupy z docházky v nich.
     */
    public function testStartBeforeProcessedMonthsNeedsDecisionAndAdvanceTakesMonthsOver(): void
    {
        $file = SyntheticPohodaPayroll::write($this->tmp);
        $supplierId = $this->payrollSupplier();

        $behind = array_values(array_filter(
            $this->importer->preflight($supplierId, $file, SyntheticPohodaPayroll::YEAR),
            static fn (array $m): bool => $m['code'] === PohodaPayrollImporter::START_BEHIND,
        ));
        self::assertCount(1, $behind);
        self::assertSame(['from' => '2026-01', 'to' => '2026-03', 'last' => '2026-02'], $behind[0]['context']);

        $dry = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, true);
        self::assertFalse($dry->hasErrors(), 'Zkouška nanečisto bez rozhodnutí proběhne. ' . $this->explain($dry));

        $refused = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false);
        self::assertTrue($refused->hasErrors());
        $codes = array_column(array_merge(...array_column($refused->toArray()['steps'], 'messages')), 'code');
        self::assertContains('payroll_start_decision_required', $codes);
        self::assertSame(0, $this->rows('payroll_employees', $supplierId), 'Odmítnutý převod nic nezapíše.');
        self::assertSame('2026-01-01', $this->startPeriod($supplierId));

        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false,
            startDecision: PohodaPayrollImporter::START_ADVANCE);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame('2026-03-01', $this->startPeriod($supplierId));
        $codes = array_column(array_merge(...array_column($protocol->toArray()['steps'], 'messages')), 'code');
        self::assertContains('payroll_start_advanced', $codes);
        self::assertNotContains(PohodaPayrollImporter::START_BEHIND, $codes);
        self::assertGreaterThan(0, $this->rows('payroll_employees', $supplierId));
        self::assertSame(0, $this->scalar(
            "SELECT COUNT(*) FROM payroll_deduction_agreements WHERE supplier_id = ? AND agreement_reference LIKE 'attendance:%'",
            [$supplierId],
        ), $this->explain($protocol));
        self::assertSame(0, $this->scalar(
            "SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ? AND source_kind = 'absence' AND status <> 'cancelled'",
            [$supplierId],
        ), $this->explain($protocol));

        // Vědomé ponechání začátku: měsíce počítá MyÚčto, srážky z docházky mají dohody.
        $kept = $this->payrollSupplier();
        $keep = $this->importer->run($kept, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false,
            startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($keep->hasErrors(), $this->explain($keep));
        self::assertSame('2026-01-01', $this->startPeriod($kept));
        self::assertGreaterThan(0, $this->scalar(
            "SELECT COUNT(*) FROM payroll_deduction_agreements WHERE supplier_id = ? AND agreement_reference LIKE 'attendance:%'",
            [$kept],
        ));
        self::assertGreaterThan(0, $this->scalar(
            "SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ? AND source_kind = 'absence' AND status <> 'cancelled'",
            [$kept],
        ), 'Syntetický export musí nést nepřítomnost s náhradou.');
    }

    private function startPeriod(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT start_period FROM payroll_module_state WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return (string) $stmt->fetchColumn();
    }

    /**
     * Opakovaný převod měsíce, který počítá MyÚčto, se změněným exportem: pracovní měsíce
     * z dřívější dávky se samy znovu otevřou (nový souhrn by se jinak nezapsal) a schválený
     * vstup složky, kterou nová dávka už nevede, se zruší - jinak by zůstal vedle nových
     * a mzda by ho vyplatila. Bez ručního SQL.
     */
    public function testRepeatedImportOfCountedMonthReopensWorkMonthsAndCancelsSupersededInputs(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = SyntheticPohodaPayroll::write($this->tmp);
        $first = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, null, null, null, false, true, PohodaPayrollImporter::START_KEEP);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        $jana = $this->employment($supplierId, '1001');
        $bonus = "SELECT COUNT(*) FROM payroll_inputs i JOIN payroll_component_definitions c ON c.id = i.component_id
                   WHERE i.supplier_id = ? AND i.employment_id = ? AND i.period_start = '2026-02-01' AND c.code LIKE 'PAM_O01%' AND i.status = ?";
        self::assertSame(1, $this->scalar($bonus, [$supplierId, $jana['id'], 'approved']), $this->explain($first));
        $months = "SELECT COUNT(*) FROM payroll_time_months WHERE supplier_id = ? AND employment_id = ? AND period_start = '2026-02-01' AND status = 'approved'";
        self::assertSame(1, $this->scalar($months, [$supplierId, $jana['id']]));

        // PAMICA únorovou odměnu zrušila: sešit února se změní, leden zůstává.
        $changedDir = $this->tmp . '/changed/' . basename(dirname($file));
        mkdir($changedDir, 0755, true);
        $changed = $changedDir . '/' . basename($file);
        $xml = (string) file_get_contents($file);
        $without = preg_replace('~<MZslozky><ID>112</ID>.*?</MZslozky>~s', '', $xml, 1);
        self::assertNotSame($xml, $without);
        file_put_contents($changed, $without);

        $again = $this->importer->run($supplierId, $this->userId, $changed, SyntheticPohodaPayroll::YEAR, false, null, null, null, false, true, PohodaPayrollImporter::START_KEEP);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $counts = self::stepCounts($again, PohodaPayrollImporter::STEP_MONTHS);
        self::assertSame(1, $counts['superseded_inputs'] ?? 0, $this->explain($again));
        self::assertGreaterThanOrEqual(1, $counts['work_months_reopened'] ?? 0, $this->explain($again));
        self::assertSame(0, $this->scalar($bonus, [$supplierId, $jana['id'], 'approved']), $this->explain($again));
        self::assertSame(1, $this->scalar($bonus, [$supplierId, $jana['id'], 'cancelled']));
        self::assertSame(1, $this->scalar($months, [$supplierId, $jana['id']]), 'Nový souhrn se zapsal a schválil znovu.');
        $january = "SELECT COUNT(*) FROM payroll_inputs i JOIN payroll_component_definitions c ON c.id = i.component_id
                     WHERE i.supplier_id = ? AND i.employment_id = ? AND i.period_start = '2026-01-01' AND c.code LIKE 'PAM_O01%' AND i.status = 'approved'";
        self::assertSame(1, $this->scalar($january, [$supplierId, $jana['id']]), 'Nezměněný měsíc převod nechá být.');
    }

    /** Izolovaná firma se zapnutými mzdami a výchozí účtárnou (stejně jako test importu docházky). */
    private function payrollSupplier(): int
    {
        $pdo = $this->db->pdo();
        $supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())',
        )->execute([$supplierId, $this->userId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "IMP", "Syntetická účtárna", "1234567890", 1)',
        )->execute([$supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2026-01-01", "1234567890", "synthetic:pohoda-payroll")',
        )->execute([$supplierId, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$supplierId, $officeId]);
        return $supplierId;
    }

    /** @return array<string,mixed> */
    private function employment(int $supplierId, string $code): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, employee_id, status, end_date FROM payroll_employments WHERE supplier_id = ? AND code = ?');
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Vztah {$code} nevznikl.");
        return $row;
    }

    /** @return array<string,string> položka => stav */
    private function checklist(int $supplierId, int $employmentId, string $phase): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT item_key, status FROM payroll_employment_checklist_items WHERE supplier_id = ? AND employment_id = ? AND phase = ? ORDER BY item_key'
        );
        $stmt->execute([$supplierId, $employmentId, $phase]);
        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function rows(string $table, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int|float> */
    private static function stepCounts(ImportProtocol $protocol, string $key): array
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            if ($step['key'] === $key) {
                return $step['counts'];
            }
        }
        return [];
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps'], 'preflight' => $protocol->get('preflight')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
