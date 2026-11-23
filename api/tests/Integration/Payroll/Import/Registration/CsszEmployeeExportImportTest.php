<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Registration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\JmhzReportFixtures;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\RegistrationXmlFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Export zaměstnanců z ePortálu ČSSZ v importu registrací nad izolovanou
 * syntetickou firmou (VS účtárny 1234567890, mzdy v MyÚčtu od dubna 2026).
 */
#[Group('integration')]
final class CsszEmployeeExportImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const ID_PPV = '200000000000000000101';

    private Connection $db;
    private ContainerInterface $container;
    private RegistrationImportService $imports;
    private RegistrationImportLookup $lookup;
    private int $supplierId;
    private int $userId;
    private string $birthNumber;
    private string $oic;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 6) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $this->container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $this->container->get(Connection::class);
        $this->imports = $this->container->get(RegistrationImportService::class);
        $this->lookup = $this->container->get(RegistrationImportLookup::class);
        if (!$this->db->hasTable('payroll_person_external_ids')) {
            self::markTestSkipped('Chybí tabulka payroll_person_external_ids.');
        }
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            "UPDATE supplier SET payroll_enabled = 1, accounting_mode = 'double_entry' WHERE id = ?"
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetická účetní", "readonly", "cs", 1)'
        )->execute([
            'cssz-export-' . bin2hex(random_bytes(4)) . '@invalid.example',
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
            "INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, 'active', '2026-04-01')"
        )->execute([$this->supplierId]);
        $this->birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $this->oic = RegistrationXmlFixtures::oic(7);
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

    /**
     * Export nese osobu a identifikátory, hlášení nástup. Hlášení je v dávce
     * PŘED exportem, přesto se export zapíše dřív a formuláře se spárují
     * podle ID PPV samy.
     */
    public function testExportWithMonthlyReportsCreatesPersonAndFormsPairByIdPpv(): void
    {
        $files = [
            $this->file('jmhz-01.xml', $this->report(1)),
            $this->file('jmhz-02.xml', $this->report(2)),
            $this->file('zamestnanci.xml', RegistrationXmlFixtures::csszExport([$this->employee()])),
        ];

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        self::assertSame(['JMHZ', 'JMHZ', 'CSSZ_EXPORT'], array_column($preview['files'], 'document_type'));
        self::assertNull($preview['files'][2]['error']);
        $byType = $this->byType($preview['records']);
        $export = $byType['CSSZ_EXPORT'][0];
        self::assertSame('Export zaměstnanců ČSSZ', $export['action_label']);
        self::assertSame('create_person', $export['operation'], $this->dump($export));
        self::assertTrue($export['selectable']);
        self::assertSame('2026-01-01', $export['employment']['start_on']);
        self::assertSame('employment', $export['employment']['relation_type']);
        self::assertTrue($this->hasWarning($export, 'první den nejstaršího hlášeného měsíce'));
        self::assertCount(2, $byType['JMHZ']);
        foreach ($byType['JMHZ'] as $form) {
            self::assertSame('pair_required', $form['operation'], $this->dump($form));
            self::assertTrue($this->hasWarning($form, 'založí věta exportu zaměstnanců ČSSZ'));
        }

        $keys = array_column(array_filter($preview['records'], static fn (array $r): bool => $r['selectable']), 'key');
        self::assertCount(3, $keys);
        $applied = $this->apply($files, $keys);
        $results = array_column($applied['results'], null, 'key');
        $exportResult = $results[$export['key']];
        self::assertSame('applied', $exportResult['status'], (string) $exportResult['message']);
        self::assertNull($exportResult['message'], (string) $exportResult['message']);
        foreach (['person_created', 'activated', 'identifiers'] as $operation) {
            self::assertContains($operation, $exportResult['operations']);
        }
        $employeeId = (int) $exportResult['employee_id'];
        $employmentId = (int) $exportResult['employment_id'];
        // Leden zapíše podmínky a prohlášení; únor pak v evidenci už nic nového nemá.
        $january = $results[$byType['JMHZ'][0]['key']];
        self::assertSame('applied', $january['status'], (string) $january['message']);
        self::assertContains('terms', $january['operations']);
        foreach ($byType['JMHZ'] as $form) {
            self::assertSame($employmentId, $results[$form['key']]['employment_id']);
        }

        $employment = $this->lookup->employment($this->supplierId, $employmentId);
        self::assertSame('active', $employment['status']);
        self::assertSame('2026-01-01', $employment['start_date']);
        // Odhad z nejstaršího hlášeného měsíce zůstane evidovaný i po zápisu,
        // ne jen jako varování v náhledu.
        $flag = $this->container->get(Connection::class)->pdo()->prepare(
            'SELECT start_estimated FROM payroll_employments WHERE supplier_id = ? AND id = ?'
        );
        $flag->execute([$this->supplierId, $employmentId]);
        self::assertSame(1, (int) $flag->fetchColumn());
        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        self::assertTrue($identities->activePersonExternalIdMatches($this->supplierId, $employeeId, 'test', $this->oic));
        self::assertTrue($identities->activeEmploymentExternalIdMatches($this->supplierId, $employmentId, 'test', self::ID_PPV));
        self::assertSame('Jana Testovací', $this->lookup->employeeName($this->supplierId, $employeeId));

        $again = $this->byType($this->imports->preview($this->supplierId, 'test', $files)['records']);
        self::assertSame('none', $again['CSSZ_EXPORT'][0]['operation'], $this->dump($again['CSSZ_EXPORT'][0]));
        self::assertSame('matched', $again['CSSZ_EXPORT'][0]['match']['status']);
        self::assertSame($employmentId, $again['CSSZ_EXPORT'][0]['match']['employment_id']);
        self::assertSame(1, $this->tableRows('payroll_employees'));
        self::assertSame(1, $this->tableRows('payroll_employments'));
    }

    public function testInsuranceStartInsideMonthNeedsNoCheck(): void
    {
        $files = [
            $this->file('zamestnanci.xml', RegistrationXmlFixtures::csszExport([$this->employee()])),
            $this->file('jmhz-03.xml', $this->report(3, '2026-03-16')),
        ];

        $export = $this->byType($this->imports->preview($this->supplierId, 'test', $files)['records'])['CSSZ_EXPORT'][0];

        self::assertSame('create_person', $export['operation'], $this->dump($export));
        self::assertSame('2026-03-16', $export['employment']['start_on']);
        self::assertTrue($this->hasWarning($export, 'začátek pojištění 2026-03-16'));
        self::assertFalse($this->hasWarning($export, 'první den nejstaršího'));
    }

    public function testExportAloneCannotCreatePerson(): void
    {
        $files = [$this->file('zamestnanci.xml', RegistrationXmlFixtures::csszExport([$this->employee()]))];

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        $export = $preview['records'][0];
        self::assertSame('create_person', $export['operation']);
        self::assertFalse($export['selectable']);
        self::assertStringContainsString('měsíční hlášení', (string) $export['blocker']);
        self::assertSame(1, $preview['summary']['blocked']);

        $result = $this->apply($files, [$export['key']])['results'][0];
        self::assertSame('skipped', $result['status']);
        self::assertSame(0, $this->tableRows('payroll_employees'));
    }

    public function testExportOfAnotherEmployerIsBlocked(): void
    {
        $files = [
            $this->file('zamestnanci.xml', RegistrationXmlFixtures::csszExport([
                $this->employee(['VariabilniSymbol' => '9876543210']),
            ])),
            $this->file('jmhz-01.xml', $this->report(1)),
        ];

        $export = $this->byType($this->imports->preview($this->supplierId, 'test', $files)['records'])['CSSZ_EXPORT'][0];

        self::assertFalse($export['selectable']);
        self::assertStringContainsString('jiného zaměstnavatele', (string) $export['blocker']);
    }

    /** Existující vztah bez identifikátorů: export doplní OIČ a ID PPV, druh činnosti jen ověří. */
    public function testExportAssignsIdentifiersToExistingEmploymentAndVerifiesActivity(): void
    {
        $a1 = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $this->birthNumber, 'start' => '2026-01-01']))];
        $created = $this->apply($a1, [$this->imports->preview($this->supplierId, 'test', $a1)['records'][0]['key']])['results'][0];
        self::assertSame('applied', $created['status'], (string) $created['message']);
        $employeeId = (int) $created['employee_id'];
        $employmentId = (int) $created['employment_id'];

        $files = [$this->file('zamestnanci.xml', RegistrationXmlFixtures::csszExport([
            $this->employee(['KodDruhuCinnosti' => '3']),
        ]))];
        $export = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertSame('assign_identifiers', $export['operation'], $this->dump($export));
        self::assertSame('birth_number', $export['match']['matched_by']);
        self::assertSame($employmentId, $export['match']['employment_id']);
        self::assertTrue($this->hasWarning($export, 'se liší od exportu ČSSZ (3)'));

        $result = $this->apply($files, [$export['key']])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertSame(['identifiers'], $result['operations']);
        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        self::assertTrue($identities->activePersonExternalIdMatches($this->supplierId, $employeeId, 'test', $this->oic));
        self::assertTrue($identities->activeEmploymentExternalIdMatches($this->supplierId, $employmentId, 'test', self::ID_PPV));
        // Seznam zaměstnanců ČSSZ je podklad, o který se smí opřít odhláška A2
        // převzatého zaměstnance bez dohlášení A3 (rozhodnutí 26. 9. 2026).
        self::assertSame('cssz_employee_export', $this->scalar(
            'SELECT source_origin FROM payroll_employment_external_ids WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        ));
        self::assertSame('cssz_employee_export', $this->scalar(
            'SELECT source_origin FROM payroll_person_external_ids WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $employeeId],
        ));
        self::assertSame('1', $this->scalar(
            'SELECT activity_code FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        ));
        self::assertSame(1, $this->tableRows('payroll_employments'));
    }

    /** Vztah jen naplánovaný z přihlášky před nástupem: ID PPV v exportu ho aktivuje k plánovanému nástupu. */
    public function testExportActivatesPlannedEmploymentFromPreRegistration(): void
    {
        $p1 = [$this->file('p1.xml', RegistrationXmlFixtures::prezecP1($this->birthNumber, 'Jana', 'Testovací', '2026-08-01'))];
        $planned = $this->apply($p1, [$this->imports->preview($this->supplierId, 'test', $p1)['records'][0]['key']])['results'][0];
        self::assertSame('applied', $planned['status'], (string) $planned['message']);
        $employmentId = (int) $planned['employment_id'];
        self::assertSame('planned', $this->lookup->employment($this->supplierId, $employmentId)['status']);

        $files = [$this->file('zamestnanci.xml', RegistrationXmlFixtures::csszExport([$this->employee()]))];
        $export = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertSame($employmentId, $export['match']['employment_id'], $this->dump($export));
        self::assertSame('update', $export['operation'], $this->dump($export));
        self::assertContains(
            ['field' => 'status', 'label' => 'Stav vztahu', 'current' => 'planned', 'imported' => 'active'],
            $export['changes'],
        );

        $result = $this->apply($files, [$export['key']])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertContains('activated', $result['operations']);
        self::assertContains('identifiers', $result['operations']);
        $employment = $this->lookup->employment($this->supplierId, $employmentId);
        self::assertSame('active', $employment['status']);
        self::assertSame('2026-08-01', $employment['start_date']);
        self::assertSame(1, $this->tableRows('payroll_employments'));
    }

    /**
     * Export od 15. 10. 2026 nese začátek pojistného vztahu: je to nástup a osoba
     * se založí i bez měsíčních hlášení. Konec pojistného vztahu nový vztah
     * rovnou ukončí.
     */
    public function testExportFrom2026OctoberCarriesStartAndEnd(): void
    {
        $files = [$this->file('zamestnanci.xml', RegistrationXmlFixtures::csszExport([
            $this->employee(['PojistnyVztahOd' => '2019-05-01', 'PojistnyVztahDo' => '2025-11-30']),
        ]))];

        $export = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertSame('create_person', $export['operation'], $this->dump($export));
        self::assertNull($export['blocker'], $this->dump($export));
        self::assertSame('2019-05-01', $export['employment']['start_on']);
        self::assertSame('2025-11-30', $export['employment']['end_on']);
        self::assertTrue($this->hasWarning($export, 'začátek pojistného vztahu podle exportu'));

        $result = $this->apply($files, [$export['key']])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertContains('terminated', $result['operations']);
        $employment = $this->lookup->employment($this->supplierId, (int) $result['employment_id']);
        self::assertSame(['2019-05-01', '2025-11-30', 'ended'], [$employment['start_date'], $employment['end_date'], $employment['status']]);
    }

    /** Nástup z exportu má přednost před odhadem z prvního hlášeného měsíce dávky. */
    public function testExportStartWinsOverFirstReportedMonth(): void
    {
        $files = [
            $this->file('jmhz-01.xml', $this->report(1)),
            $this->file('zamestnanci.xml', RegistrationXmlFixtures::csszExport([$this->employee(['PojistnyVztahOd' => '2021-09-01'])])),
        ];

        $export = $this->byType($this->imports->preview($this->supplierId, 'test', $files)['records'])['CSSZ_EXPORT'][0];

        self::assertSame('2021-09-01', $export['employment']['start_on'], $this->dump($export));
        self::assertFalse($this->hasWarning($export, 'první den nejstaršího'));
    }

    /** U zaměstnání malého rozsahu a DPP není začátek pojištění nástupem (pokyny k 10223). */
    public function testSmallScaleInsuranceStartIsNotTakenAsStart(): void
    {
        $files = [
            $this->file('jmhz-03.xml', $this->report(3, '2026-03-16')),
            $this->file('zamestnanci.xml', RegistrationXmlFixtures::csszExport([
                $this->employee(['PojistnyVztahOd' => '2026-03-01', 'ZMR' => 'A']),
            ])),
        ];

        $export = $this->byType($this->imports->preview($this->supplierId, 'test', $files)['records'])['CSSZ_EXPORT'][0];

        self::assertSame('2026-03-16', $export['employment']['start_on'], $this->dump($export));
        self::assertTrue($this->hasWarning($export, 'nemusí být den nástupu'));
    }

    /** Cizinec bez rodného čísla: export nese EČP a osoba se podle něj hledá i zakládá. */
    public function testForeignerIsIdentifiedByInsuredPersonNumber(): void
    {
        $files = [
            $this->file('zamestnanci.xml', RegistrationXmlFixtures::csszExport([
                $this->employee(['RodneCislo' => null, 'EvidencniCisloPojistence' => '9005410005', 'PojistnyVztahOd' => '2026-02-01']),
            ])),
        ];

        $export = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];

        self::assertSame('create_person', $export['operation'], $this->dump($export));
        self::assertSame('900541/****', $export['person']['birth_number_masked']);
        self::assertTrue($this->hasWarning($export, 'evidenční číslo pojištěnce (EČP)'));
    }

    /** IMP-11: EČP cizince z exportu se zapíše na kartu osoby (dřív „doplňte ručně"). */
    public function testForeignerInsuredPersonNumberIsWrittenToThePersonCard(): void
    {
        $files = [
            $this->file('zamestnanci.xml', RegistrationXmlFixtures::csszExport([
                $this->employee(['RodneCislo' => null, 'EvidencniCisloPojistence' => '9005410005', 'PojistnyVztahOd' => '2026-02-01']),
            ])),
        ];
        $export = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];

        $result = $this->apply($files, [$export['key']])['results'][0];

        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertContains('ecp', $result['operations']);
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_person_identifiers WHERE supplier_id = ? AND employee_id = ? AND identifier_type = "ecp"',
            [$this->supplierId, (int) $result['employee_id']],
        ));
    }

    /**
     * IMP-12: konec pojistného vztahu z exportu u vztahu, který evidence vede
     * jako trvající, se nabídne k ukončení; zapíše se až po potvrzení.
     */
    public function testInsuranceEndOfRunningEmploymentIsOfferedAndEndsOnlyWhenConfirmed(): void
    {
        $a1 = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $this->birthNumber, 'start' => '2026-01-01']))];
        $created = $this->apply($a1, [$this->imports->preview($this->supplierId, 'test', $a1)['records'][0]['key']])['results'][0];
        $employmentId = (int) $created['employment_id'];
        $files = [$this->file('zamestnanci.xml', RegistrationXmlFixtures::csszExport([
            $this->employee(['PojistnyVztahOd' => '2026-01-01', 'PojistnyVztahDo' => '2026-05-31']),
        ]))];

        $offer = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertSame('2026-05-31', $offer['termination_offer']['end_on'], $this->dump($offer));
        self::assertFalse($offer['termination_offer']['confirmed']);
        self::assertTrue($offer['selectable']);
        $this->apply($files, [$offer['key']]);
        self::assertNull($this->lookup->employment($this->supplierId, $employmentId)['end_date']);

        $confirmed = $this->imports->apply(
            $this->supplierId, 'test', $files, [$offer['key']], true, null, $this->userId, null,
            'cssz-export-test', null, false, false, false, false, false, null, [$offer['key']],
        )['results'][0];
        self::assertSame('applied', $confirmed['status'], (string) $confirmed['message']);
        self::assertContains('terminated', $confirmed['operations']);
        $employment = $this->lookup->employment($this->supplierId, $employmentId);
        self::assertSame(['2026-05-31', 'ended'], [$employment['end_date'], $employment['status']]);
    }

    /**
     * Vztah jen z hlášení bez exportu: nástup je odhad z prvního hlášeného měsíce
     * a náhled i výsledek to říkají, aby ho účetní doplnila ze smlouvy.
     */
    public function testStartEstimatedFromFirstReportIsFlagged(): void
    {
        $files = [$this->file('jmhz-01.xml', $this->report(1))];

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        $derived = $this->byType($preview['records'])['JMHZ_DERIVED'][0];

        self::assertTrue($derived['employment']['start_estimated'], $this->dump($derived));
        self::assertTrue($this->hasWarning($derived, 'Doplňte skutečný nástup ze smlouvy'));
        $result = array_column($this->apply($files, [$derived['key']])['results'], null, 'key')[$derived['key']];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertTrue($result['start_estimated']);
    }

    /**
     * @param array<string,string|null> $overrides
     * @return array<string,string|null>
     */
    private function employee(array $overrides = []): array
    {
        return $overrides + [
            'RodneCislo' => $this->birthNumber,
            'Prijmeni' => 'Testovací',
            'Jmeno' => 'Jana',
            'KodDruhuCinnosti' => '1',
            'ZMR' => 'N',
            'IdZamestnani' => self::ID_PPV,
            'OIC' => $this->oic,
        ];
    }

    private function report(int $month, ?string $insuranceFrom = null): string
    {
        return JmhzReportFixtures::report([JmhzReportFixtures::person([
            'oic' => $this->oic,
            'id_ppv' => self::ID_PPV,
            'insurance_from' => $insuranceFrom,
        ])], 2026, $month);
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return array<string,list<array<string,mixed>>>
     */
    private function byType(array $records): array
    {
        $result = [];
        foreach ($records as $record) {
            $result[$record['document_type']][] = $record;
        }

        return $result;
    }

    /** @param array<string,mixed> $plan */
    private function hasWarning(array $plan, string $needle): bool
    {
        foreach ($plan['warnings'] as $warning) {
            if (str_contains($warning, $needle)) {
                return true;
            }
        }

        return false;
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
            'cssz-export-test',
        );
    }

    /** @return array{name:string,content_base64:string} */
    private function file(string $name, string $content): array
    {
        return ['name' => $name, 'content_base64' => base64_encode($content)];
    }

    private function tableRows(string $table): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?", [$this->supplierId]);
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    private function dump(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
