<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Registration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzDerivedRegistrations;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportService;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\JmhzReportFixtures;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\RegistrationXmlFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Firma, která přechází z jiného programu a má jen přijatá měsíční hlášení:
 * import z nich založí zaměstnance, převezme historii mezd do společné vrstvy
 * převzatých mezd a doplní, co řada měsíců dokládá. Mzdy vede MyÚčto od dubna
 * 2026; leden až březen jsou měsíce předchozího programu.
 */
#[Group('integration')]
final class JmhzTakeoverImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const MODULE_START = '2026-04-01';
    private const PPV_A = '200000000000000000201';
    private const PPV_B = '200000000000000000202';

    private Connection $db;
    private RegistrationImportService $imports;
    private PayrollSensitiveData $sensitive;
    private PayrollEmploymentRepository $employments;
    private PayrollRegistrationIdentityService $registrations;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 6) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->imports = $container->get(RegistrationImportService::class);
        $this->sensitive = $container->get(PayrollSensitiveData::class);
        $this->employments = $container->get(PayrollEmploymentRepository::class);
        $this->registrations = $container->get(PayrollRegistrationIdentityService::class);
        if (!$this->db->hasTable('payroll_migration_reference_totals')) {
            self::markTestSkipped('Tabulky převzatých mezd v testovací DB nejsou.');
        }
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare("UPDATE supplier SET payroll_enabled = 1 WHERE id = ?")->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetická účetní", "readonly", "cs", 1)'
        )->execute([
            'jmhz-takeover-' . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);
        $this->userId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, 'IMPORT', 'Syntetická účtárna', '1234567890', 1)"
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id) VALUES (?, ?)'
        )->execute([$this->supplierId, (int) $pdo->lastInsertId()]);
        $pdo->prepare(
            "INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, 'active', ?)"
        )->execute([$this->supplierId, self::MODULE_START]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testReportsAloneCreatePeopleAndTakeOverTheirPayrollHistory(): void
    {
        $files = $this->reports();

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        $derived = array_values(array_filter(
            $preview['records'],
            static fn (array $record): bool => $record['document_type'] === 'JMHZ_DERIVED',
        ));
        self::assertCount(2, $derived, $this->dump($preview['records']));
        self::assertSame(['create_person', 'create_person'], array_column($derived, 'operation'));
        self::assertSame(JmhzDerivedRegistrations::PLACEHOLDER_FIRST_NAME, $derived[0]['person']['first_name']);
        self::assertSame('2026-01-01', $derived[0]['employment']['start_on']);
        self::assertSame('2026-04', $preview['takeover']['start_period']);
        self::assertSame(['2026-01', '2026-02', '2026-03'], array_column($preview['takeover']['months'], 'period'));

        $keys = array_values(array_map(
            static fn (array $record): string => $record['key'],
            array_filter($preview['records'], static fn (array $record): bool => $record['selectable']),
        ));
        $result = $this->apply($files, $keys);

        self::assertSame(0, $result['summary']['failed'], $this->dump($result['results']));
        self::assertSame(2, $this->rowCount('payroll_employees'));
        $takeover = $result['takeover'];
        self::assertSame(5, $takeover['saved'], $this->dump($takeover));
        self::assertSame([], $takeover['skipped'], $this->dump($takeover));
        // Firma číselník mzdových složek ještě neotevřela; předpis mzdy si ho založí.
        self::assertSame(1, $takeover['counts']['recurring_wage'] ?? null, $this->dump($takeover));
        self::assertSame(['2026-01', '2026-02', '2026-03'], $takeover['periods']);
        self::assertSame(1, $takeover['counts']['monthly_wage'] ?? null, $this->dump($takeover));
        self::assertSame(1, $takeover['counts']['leave_taken'] ?? null, $this->dump($takeover));
        self::assertSame(1, $takeover['counts']['ended'] ?? null, $this->dump($takeover));
        self::assertSame(2, $takeover['counts']['averages'] ?? null, $this->dump($takeover));

        $employmentA = $this->employmentByPpv(self::PPV_A);
        $employmentB = $this->employmentByPpv(self::PPV_B);
        self::assertSame(
            ['jmhz', '2026-01-01', 4_000_000],
            $this->totals($employmentA, '2026-01-01'),
        );
        self::assertSame(3, $this->rowCount('payroll_migration_reference_totals', 'employment_id = ' . $employmentA));
        self::assertSame(2, $this->rowCount('payroll_migration_reference_totals', 'employment_id = ' . $employmentB));
        self::assertSame(4_000_000, $this->scalar('SELECT monthly_gross_minor FROM payroll_employments WHERE id = ?', [$employmentA]));
        self::assertSame('ended', $this->scalar('SELECT status FROM payroll_employments WHERE id = ?', [$employmentB]));
        self::assertSame('2026-02-15', $this->scalar('SELECT end_date FROM payroll_employments WHERE id = ?', [$employmentB]));
        self::assertSame(-480, $this->scalar(
            "SELECT minutes_delta FROM payroll_leave_ledger WHERE employment_id = ? AND entry_type = 'taken'",
            [$employmentA],
        ));
        self::assertSame(1, $this->rowCount('payroll_average_earning_snapshots', "status = 'approved' AND employment_id = " . $employmentA));

        // Opakovaný import nic nezdvojí.
        $again = $this->imports->preview($this->supplierId, 'test', $files);
        $keys = array_values(array_map(
            static fn (array $record): string => $record['key'],
            array_filter($again['records'], static fn (array $record): bool => $record['selectable']),
        ));
        $second = $this->apply($files, $keys);
        self::assertSame(2, $this->rowCount('payroll_employees'));
        self::assertSame(5, $this->rowCount('payroll_migration_reference_totals'));
        self::assertSame(1, $this->rowCount('payroll_leave_ledger', "entry_type = 'taken'"));
        self::assertSame(1, $second['takeover']['counts']['leave_taken_existing'] ?? null, $this->dump($second['takeover']));
        self::assertArrayNotHasKey('monthly_wage', $second['takeover']['counts'], 'Mzdu, kterou vztah už nese, převzetí nepřepisuje.');
    }

    /**
     * Vztah založený z pozdějšího hlášení: dávka se staršími měsíci nástup posune
     * dřív — vztah, podmínky, událost aktivace, identifikátory i identita osoby.
     */
    public function testOlderReportsMoveTheStartOfAnEmploymentCreatedFromLaterOnes(): void
    {
        $files = $this->reports();
        $march = [$files[2]];
        $first = $this->imports->preview($this->supplierId, 'test', $march);
        $this->apply($march, $this->selectable($first));
        $employment = $this->employmentByPpv(self::PPV_A);
        self::assertSame('2026-03-01', $this->scalar('SELECT start_date FROM payroll_employments WHERE id = ?', [$employment]));

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        $derived = array_values(array_filter(
            $preview['records'],
            static fn (array $record): bool => $record['document_type'] === 'JMHZ_DERIVED'
                && $record['match']['employment_id'] === $employment,
        ));
        self::assertCount(1, $derived, $this->dump($preview['records']));
        self::assertSame('update', $derived[0]['operation']);
        self::assertContains(
            ['field' => 'start_on', 'label' => 'Nástup', 'current' => '2026-03-01', 'imported' => '2026-01-01'],
            $derived[0]['changes'],
        );

        $result = $this->apply($files, $this->selectable($preview));

        self::assertSame(0, $result['summary']['failed'], $this->dump($result['results']));
        $params = [$employment];
        self::assertSame('2026-01-01', $this->scalar('SELECT start_date FROM payroll_employments WHERE id = ?', $params));
        self::assertSame('2026-01-01', $this->scalar('SELECT actual_start_date FROM payroll_employments WHERE id = ?', $params));
        self::assertSame('2026-01-01', $this->scalar('SELECT MIN(effective_from) FROM payroll_employment_terms WHERE employment_id = ?', $params));
        self::assertSame('2026-01-01', $this->scalar(
            "SELECT effective_on FROM payroll_employment_events WHERE employment_id = ? AND event_type = 'status_changed' AND to_status = 'active'",
            $params,
        ));
        self::assertSame('2026-01-01', $this->scalar('SELECT MIN(valid_from) FROM payroll_employment_external_ids WHERE employment_id = ?', $params));
        self::assertSame('2026-01-01', $this->scalar(
            'SELECT MIN(h.effective_from) FROM payroll_person_identity_history h
               JOIN payroll_employments e ON e.employee_id = h.employee_id WHERE e.id = ?',
            $params,
        ));
        self::assertSame(3, $this->rowCount('payroll_migration_reference_totals', 'employment_id = ' . $employment));
        self::assertSame(1, $this->rowCount('payroll_employees', 'id IN (SELECT employee_id FROM payroll_employments WHERE id = ' . $employment . ')'));

        // Pozdější nástup se nikdy nezapisuje.
        try {
            $this->employments->correctStartEarlier($this->supplierId, $employment, '2026-02-01', null, 'test', $this->userId, null, null);
            self::fail('Posun nástupu na pozdější den musí selhat.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('dřívější', $e->getMessage());
        }

        // Znovu nic neposouvá.
        $again = $this->imports->preview($this->supplierId, 'test', $files);
        foreach ($again['records'] as $record) {
            self::assertNotContains('start_on', array_column($record['changes'], 'field'), $this->dump($record));
        }
    }

    /**
     * Registrace nahraná až po hlášeních: osobu se zástupným jménem dohledá
     * přes OIČ a doplní, co hlášení nenesla — jméno, rodné číslo, datum
     * narození a skutečný nástup.
     */
    public function testLaterRegistrationReplacesPlaceholderPersonData(): void
    {
        $files = $this->reports();
        $this->apply($files, $this->selectable($this->imports->preview($this->supplierId, 'test', $files)));
        $employment = $this->employmentByPpv(self::PPV_A);
        $employee = (int) $this->scalar('SELECT employee_id FROM payroll_employments WHERE id = ?', [$employment]);
        self::assertStringStartsWith(JmhzDerivedRegistrations::PLACEHOLDER_FIRST_NAME, (string) $this->scalar(
            'SELECT full_name FROM payroll_employees WHERE id = ?',
            [$employee],
        ));

        $birthNumber = RegistrationXmlFixtures::birthNumber('1988-03-04', 'female', 7);
        $xml = str_replace(
            ['act="1" dat="2026-07-02"', '</client>', '<job ', '<position name="Účetní"/>'],
            [
                'act="3" dat="2026-07-02" fro="2026-07-02"',
                '<taxidrezid stat="CZ" statchang="2026-07-02"/></client>',
                '<job relat="1111" workmode="1" cont="N" ',
                '<position name="Účetní" lead="N"/>',
            ],
            RegistrationXmlFixtures::regzecA1([
                'bno' => $birthNumber,
                'first' => 'Petra',
                'last' => 'Syntetická',
                'tit' => null,
                'birth_date' => '1988-03-04',
                'start' => '2019-05-01',
                'ikmpsv' => RegistrationXmlFixtures::oic(21),
                'oid' => self::PPV_A,
            ]),
        );
        $regzec = [$this->file('regzec.xml', $xml)];
        $preview = $this->imports->preview($this->supplierId, 'test', $regzec);
        self::assertCount(1, $preview['records'], $this->dump($preview['records']));
        $record = $preview['records'][0];
        self::assertSame('oic', $record['match']['matched_by'], $this->dump($record));
        self::assertSame('update', $record['operation'], $this->dump($record));
        foreach ($record['warnings'] as $warning) {
            self::assertStringNotContainsString('se liší od evidence', $warning);
        }

        $result = $this->apply($regzec, $this->selectable($preview));

        self::assertSame(0, $result['summary']['failed'], $this->dump($result['results']));
        self::assertSame(null, $result['results'][0]['message'] ?? null, $this->dump($result['results']));
        self::assertSame('Petra Syntetická', $this->scalar('SELECT full_name FROM payroll_employees WHERE id = ?', [$employee]));
        self::assertSame('1988-03-04', $this->scalar(
            'SELECT birth_date FROM payroll_person_identity_history WHERE employee_id = ?',
            [$employee],
        ));
        self::assertSame('2019-05-01', $this->scalar(
            'SELECT MIN(effective_from) FROM payroll_person_identity_history WHERE employee_id = ?',
            [$employee],
        ));
        self::assertSame(
            $this->sensitive->lookupHash($birthNumber, PayrollSensitiveField::PERSONAL_IDENTIFIER, $this->supplierId),
            $this->scalar(
                "SELECT value_hash FROM payroll_person_identifiers WHERE employee_id = ? AND identifier_type = 'birth_number'",
                [$employee],
            ),
        );
        self::assertSame('2019-05-01', $this->scalar('SELECT start_date FROM payroll_employments WHERE id = ?', [$employment]));
        self::assertSame(0, $this->scalar('SELECT start_estimated FROM payroll_employments WHERE id = ?', [$employment]));
        self::assertSame(
            ['czech-resident', 'CZ', '2019-05-01'],
            array_values((array) $this->db->pdo()->query(
                'SELECT residence, country_code, effective_from FROM payroll_person_tax_residences WHERE employee_id = ' . $employee,
            )?->fetch(\PDO::FETCH_ASSOC)),
        );
        $profile = $this->registrations->a1ProfileView($this->supplierId, $employment)['profile'];
        self::assertIsArray($profile);
        self::assertSame('1111', $profile['employment']['employment_status_code']);
        self::assertSame('1', $profile['employment']['work_mode_code']);
        self::assertFalse($profile['employment']['continuous_operation']);
        self::assertFalse($profile['employment']['leadership']);
        self::assertSame('Účetní', $profile['employment']['position_name']);
        self::assertSame('M', $profile['facts']['highest_education_code']);
        self::assertSame('12', $profile['permanent_address']['house_number']);

        $again = $this->imports->preview($this->supplierId, 'test', $regzec);
        self::assertSame('none', $again['records'][0]['operation'], $this->dump($again['records'][0]));
    }

    /**
     * Osoba se dvěma vztahy (souběh): formulář druhého vztahu nese OIČ osoby,
     * ale jiné ID PPV. Nesmí se přes OIČ přilepit k prvnímu vztahu — dva
     * formuláře na jednom vztahu by import zablokoval jako konflikt.
     */
    public function testConcurrentEmploymentOfTheSamePersonIsNotPairedByPersonIdentifier(): void
    {
        $oic = RegistrationXmlFixtures::oic(31);
        $first = JmhzReportFixtures::report([JmhzReportFixtures::person([
            'employment_id' => 301, 'id_ppv' => '200000000000000000301', 'oic' => $oic, 'children' => [],
        ])], 2026, 1, ['guid_seed' => 71]);
        $files = [$this->file('jmhz-1.xml', $first)];
        $this->apply($files, $this->selectable($this->imports->preview($this->supplierId, 'test', $files)));

        $second = JmhzReportFixtures::report([
            JmhzReportFixtures::person(['employment_id' => 301, 'id_ppv' => '200000000000000000301', 'oic' => $oic, 'children' => []]),
            JmhzReportFixtures::person([
                'employment_id' => 302, 'id_ppv' => '200000000000000000302', 'oic' => $oic, 'children' => [],
                'primary' => false, 'wage' => 5_000, 'taxable' => 5_000, 'social_base' => 5_000,
            ]),
        ], 2026, 2, ['guid_seed' => 72]);
        $files[] = $this->file('jmhz-2.xml', $second);

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        $blocked = array_values(array_filter($preview['records'], static fn (array $record): bool => $record['blocker'] !== null));
        self::assertSame([], $blocked, $this->dump($blocked));

        $result = $this->apply($files, $this->selectable($preview));
        self::assertSame(0, $result['summary']['failed'], $this->dump($result['results']));
        self::assertSame(2, $this->rowCount('payroll_employments'));
        self::assertSame(3, $this->rowCount('payroll_migration_reference_totals'));
    }

    /**
     * PRE-04, NRO-02, NRO-04: poslední převzaté hlášení (březen) vykazuje
     * nemoc. Převzetí založí na vztahu úkol ověřit rozběhnutou neschopnost
     * a převzatý měsíc nese vyloučené dny § 18 odst. 7 (10366) zvlášť od
     * vyloučených dob 10357 i příjem z nepojištěné činnosti (10476).
     */
    public function testSicknessInLastMonthCreatesFollowUp(): void
    {
        $person = fn (): array => JmhzReportFixtures::person([
            'employment_id' => 201,
            'id_ppv' => self::PPV_A,
            'oic' => RegistrationXmlFixtures::oic(21),
            'children' => [],
        ]);
        $files = [];
        foreach ([1, 2, 3] as $month) {
            $xml = JmhzReportFixtures::report([$person()], 2026, $month, ['guid_seed' => 60 + $month]);
            if ($month === 2) {
                $xml = JmhzReportFixtures::withExcludedDays($xml, self::PPV_A, [
                    'vylouceneDobyCelkem' => 0, 'vyloucenePar18' => 28, 'omluvenaNepritomnost' => 28,
                ], 0, 0);
            }
            if ($month === 3) {
                $xml = JmhzReportFixtures::withExcludedDays($xml, self::PPV_A, [
                    'vylouceneDobyCelkem' => 4, 'docasNeschopnost' => 4, 'vyloucenePar18' => 14,
                    'pracovniNeschopnost' => 10, 'vyplaceniDavek' => 4,
                ]);
            }
            $files[] = $this->file("jmhz-{$month}.xml", $xml);
        }

        $result = $this->apply($files, $this->selectable($this->imports->preview($this->supplierId, 'test', $files)));

        self::assertSame(0, $result['summary']['failed'], $this->dump($result['results']));
        $employment = $this->employmentByPpv(self::PPV_A);
        self::assertSame(1, $this->rowCount(
            'payroll_employment_checklist_items',
            "item_key = 'takeover_sickness_review' AND employment_id = " . $employment,
        ));
        self::assertSame(
            [0, 28, 0],
            array_map('intval', array_values((array) $this->db->pdo()->query(
                "SELECT excluded_days, sickness_excluded_days, uninsured_income_minor
                   FROM payroll_migration_reference_totals
                  WHERE employment_id = {$employment} AND period_start = '2026-02-01'",
            )?->fetch(\PDO::FETCH_ASSOC))),
        );
        self::assertSame(
            [4, 14],
            array_map('intval', array_values((array) $this->db->pdo()->query(
                "SELECT excluded_days, sickness_excluded_days
                   FROM payroll_migration_reference_totals
                  WHERE employment_id = {$employment} AND period_start = '2026-03-01'",
            )?->fetch(\PDO::FETCH_ASSOC))),
        );
    }

    public function testReportOfAnotherEmployerIsRejected(): void
    {
        $xml = str_replace(
            '<variabilniSymbol>1234567890</variabilniSymbol>',
            '<variabilniSymbol>9876543210</variabilniSymbol>',
            JmhzReportFixtures::report([JmhzReportFixtures::person(['children' => []])], 2026, 1),
        );

        $preview = $this->imports->preview($this->supplierId, 'test', [$this->file('cizi.xml', $xml)]);

        self::assertStringContainsString('9876543210', (string) $preview['files'][0]['error']);
        self::assertSame([], $preview['records']);
    }

    /** @return list<array{name:string,content_base64:string}> */
    private function reports(): array
    {
        $full = ['unworked_total_millihours' => 0];
        $vacation = ['unworked_total_millihours' => 8_000, 'unworked_paid_millihours' => 8_000, 'vacation_millihours' => 8_000];
        $a = static fn (array $o): array => JmhzReportFixtures::person($o + [
            'employment_id' => 201,
            'id_ppv' => self::PPV_A,
            'oic' => RegistrationXmlFixtures::oic(21),
            'children' => [],
        ]);
        $b = static fn (array $o): array => JmhzReportFixtures::person($o + [
            'employment_id' => 202,
            'id_ppv' => self::PPV_B,
            'oic' => RegistrationXmlFixtures::oic(22),
            'children' => [],
        ]);
        $files = [];
        foreach ([
            // Mzda je doložená od února (v lednu dovolená), vztah trvá od ledna.
            1 => [$a(['standard_fund' => 168_000, 'agreed_fund' => 168_000, 'unworked' => $vacation]), $b([])],
            2 => [$a(['standard_fund' => 160_000, 'agreed_fund' => 160_000, 'unworked' => $full]), $b(['insurance_to' => '2026-02-15'])],
            3 => [$a(['standard_fund' => 176_000, 'agreed_fund' => 176_000, 'unworked' => $full])],
        ] as $month => $people) {
            $files[] = $this->file("jmhz-{$month}.xml", JmhzReportFixtures::report($people, 2026, $month, ['guid_seed' => 40 + $month]));
        }

        return $files;
    }

    /**
     * @param list<array{name:string,content_base64:string}> $files
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    private function apply(array $files, array $keys): array
    {
        return $this->imports->apply(
            $this->supplierId,
            'test',
            $files,
            $keys,
            true,
            null,
            $this->userId,
            null,
            'jmhz-takeover-test',
            null,
            false,
            false,
            false,
            false,
            true,
        );
    }

    /**
     * @param array<string,mixed> $preview
     * @return list<string>
     */
    private function selectable(array $preview): array
    {
        return array_values(array_map(
            static fn (array $record): string => $record['key'],
            array_filter($preview['records'], static fn (array $record): bool => $record['selectable']),
        ));
    }

    private function employmentByPpv(string $idPpv): int
    {
        $hash = $this->sensitive->lookupHash($idPpv, PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER, $this->supplierId);
        $statement = $this->db->pdo()->prepare(
            "SELECT employment_id FROM payroll_employment_external_ids
              WHERE supplier_id = ? AND identifier_type = 'id_ppv' AND value_hash = ? AND valid_to IS NULL"
        );
        $statement->execute([$this->supplierId, $hash]);
        $ids = array_map(intval(...), $statement->fetchAll(\PDO::FETCH_COLUMN));
        self::assertCount(1, $ids, "ID PPV …{$idPpv}");

        return $ids[0];
    }

    /** @return array{0:string,1:string,2:int} */
    private function totals(int $employmentId, string $periodStart): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT source, period_start, gross_minor FROM payroll_migration_reference_totals
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?'
        );
        $statement->execute([$this->supplierId, $employmentId, $periodStart]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return [(string) $row['source'], (string) $row['period_start'], (int) $row['gross_minor']];
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return is_string($value) && preg_match('/^-?\d+$/D', $value) === 1 ? (int) $value : $value;
    }

    private function rowCount(string $table, string $where = '1 = 1'): int
    {
        $statement = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$where}");
        $statement->execute([$this->supplierId]);

        return (int) $statement->fetchColumn();
    }

    /** @return array{name:string,content_base64:string} */
    private function file(string $name, string $content): array
    {
        return ['name' => $name, 'content_base64' => base64_encode($content)];
    }

    private function dump(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
