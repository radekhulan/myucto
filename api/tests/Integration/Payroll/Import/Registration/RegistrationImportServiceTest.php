<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Registration;

use MyInvoice\Action\Payroll\PayrollRegistrationImportAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportService;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\RegistrationXmlFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class RegistrationImportServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ContainerInterface $container;
    private RegistrationImportService $imports;
    private RegistrationImportLookup $lookup;
    private int $supplierId;
    private int $userId;

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
            'registration-import-' . bin2hex(random_bytes(4)) . '@invalid.example',
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

    public function testNewPersonIsCreatedCompletelyAndReimportCreatesNothing(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $files = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber]))];

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        self::assertNull($preview['files'][0]['error']);
        self::assertSame('REGZEC25', $preview['files'][0]['document_type']);
        $record = $preview['records'][0];
        self::assertSame('create_person', $record['operation'], json_encode($record, JSON_UNESCAPED_UNICODE));
        self::assertSame('new', $record['match']['status']);
        self::assertTrue($record['selectable']);
        self::assertSame('905115/****', $record['person']['birth_number_masked']);
        self::assertSame(1, $preview['summary']['create']);

        $applied = $this->apply($files, [$record['key']]);
        $result = $applied['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertNull($result['message'], (string) $result['message']);
        foreach (['person_created', 'terms', 'identity_facts', 'address', 'activated'] as $operation) {
            self::assertContains($operation, $result['operations']);
        }

        $employeeId = (int) $result['employee_id'];
        $employmentId = (int) $result['employment_id'];
        $employment = $this->lookup->employment($this->supplierId, $employmentId);
        self::assertSame('active', $employment['status']);
        self::assertSame('2026-07-01', $employment['start_date']);
        self::assertSame('111', $this->lookup->healthInsurerAt($this->supplierId, $employeeId, '2026-07-01'));
        $address = $this->lookup->addressAt($this->supplierId, $employeeId, 'residence', '2026-07-01');
        self::assertSame('Zkušební 12', $address['street_line']);
        self::assertSame('11000', $address['postal_code']);
        $identity = $this->row(
            'SELECT title_prefix, birth_place, citizenship_country_code, sex, birth_surname
               FROM payroll_person_identity_history WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $employeeId],
        );
        self::assertSame(['Ing.', 'Testov', 'CZ', 'female', 'Zkušební'], array_values($identity));
        $terms = $this->row(
            'SELECT activity_code, jmhz_relationship_detail_code, cz_isco_code,
                    work_place, jmhz_workplace_municipality_code
               FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        );
        self::assertSame(['1', '1', '43111', 'Hlavní město Praha', '554782'], array_values($terms));
        self::assertSame([$employeeId], $this->lookup->employeesByIdentifierHash(
            $this->supplierId,
            'birth_number',
            $this->sensitive()->lookupHash($birthNumber, PayrollSensitiveField::PERSONAL_IDENTIFIER, $this->supplierId),
        ));

        $again = $this->imports->preview($this->supplierId, 'test', $files);
        $record = $again['records'][0];
        self::assertSame('none', $record['operation'], json_encode($record, JSON_UNESCAPED_UNICODE));
        self::assertSame('matched', $record['match']['status']);
        self::assertSame('birth_number', $record['match']['matched_by']);
        self::assertSame($employmentId, $record['match']['employment_id']);
        self::assertFalse($record['selectable']);

        $second = $this->apply($files, [$record['key']]);
        self::assertSame('skipped', $second['results'][0]['status']);
        self::assertSame(1, $this->tableRows('payroll_employees'));
        self::assertSame(1, $this->tableRows('payroll_employments'));
    }

    /**
     * Cizí mzdové programy posílají u dohody `relDetail="1"`; evidence ho u DPP
     * nevede. Import nesmí hlásit nesoulad ani trvalou „změnu", která nejde zapsat.
     */
    public function testAgreementWithForeignRelationshipDetailImportsWithoutFalseChange(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1992-03-04', 'male', 2);
        $files = [$this->file('dpp.xml', RegistrationXmlFixtures::regzecA1([
            'bno' => $birthNumber,
            'birth_date' => '1992-03-04',
            'sex' => 'M',
            'first' => 'Petr',
            'last' => 'Zkušební',
            'rel' => 'T',
            'detail' => '1',
        ]))];

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        $record = $preview['records'][0];
        self::assertSame('create_person', $record['operation'], json_encode($record, JSON_UNESCAPED_UNICODE));
        self::assertSame('dpp', $record['employment']['relation_type']);
        foreach ($record['warnings'] as $warning) {
            self::assertStringNotContainsString('Druh činnosti', $warning);
        }

        $applied = $this->apply($files, [$record['key']]);
        self::assertSame('applied', $applied['results'][0]['status'], (string) $applied['results'][0]['message']);
        $employmentId = (int) $applied['results'][0]['employment_id'];
        $terms = $this->row(
            'SELECT activity_code, jmhz_relationship_detail_code
               FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        );
        self::assertSame(['T', null], array_values($terms));

        $again = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertSame('none', $again['operation'], json_encode($again, JSON_UNESCAPED_UNICODE));
        foreach ($again['changes'] as $change) {
            self::assertNotContains($change['field'], ['activity_code', 'jmhz_relationship_detail_code']);
        }
        foreach ($again['warnings'] as $warning) {
            self::assertStringNotContainsString('Druh činnosti', $warning);
        }
    }

    /**
     * DPČ přihlášená jako zaměstnání malého rozsahu (`sme="A"`, druh činnosti
     * A až J) zůstává DPČ, ale příznak se přenese do profilu A1, odkud ho
     * čte příští věta. Dřív se ztratil a návrh poslal `sme="N"`.
     */
    public function testAgreementToCompleteWorkKeepsSmallScaleFlagInRegistrationProfile(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1993-05-06', 'male', 4);
        $files = [$this->file('dpc.xml', RegistrationXmlFixtures::regzecA1([
            'bno' => $birthNumber,
            'birth_date' => '1993-05-06',
            'sex' => 'M',
            'first' => 'Pavel',
            'last' => 'Zkušební',
            'rel' => 'A',
            'detail' => null,
            'sme' => 'A',
        ]))];

        $record = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertSame('create_person', $record['operation'], json_encode($record, JSON_UNESCAPED_UNICODE));
        self::assertSame('dpc', $record['employment']['relation_type']);

        $applied = $this->apply($files, [$record['key']]);
        self::assertSame('applied', $applied['results'][0]['status'], (string) $applied['results'][0]['message']);
        $employmentId = (int) $applied['results'][0]['employment_id'];
        $profile = $this->container->get(PayrollRegistrationIdentityService::class)
            ->a1ProfileView($this->supplierId, $employmentId)['profile'];
        self::assertIsArray($profile);
        self::assertTrue($profile['employment']['small_scale'], json_encode($profile['employment'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Obec bez ulic: věta nese jen `num` a `cit`. Profil A1 nesmí dostat
     * ulici „Obec 424" z jednořádkové adresy, jinak by příští věta poslala
     * číslo popisné dvakrát (zpětný převod Premier, 2 věty).
     */
    public function testAddressWithoutStreetDoesNotDuplicateHouseNumberInRegistrationProfile(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1988-02-03', 'female', 5);
        $files = [$this->file('obec.xml', RegistrationXmlFixtures::regzecA1([
            'bno' => $birthNumber,
            'birth_date' => '1988-02-03',
            'street' => null,
            'num' => '424',
            'city' => 'Testov',
        ]))];

        $record = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        $applied = $this->apply($files, [$record['key']]);
        self::assertSame('applied', $applied['results'][0]['status'], (string) $applied['results'][0]['message']);
        $profile = $this->container->get(PayrollRegistrationIdentityService::class)
            ->a1ProfileView($this->supplierId, (int) $applied['results'][0]['employment_id'])['profile'];
        self::assertIsArray($profile);
        self::assertNull($profile['permanent_address']['street'] ?? null, json_encode($profile['permanent_address'], JSON_UNESCAPED_UNICODE));
        self::assertSame('424', $profile['permanent_address']['house_number']);
    }

    /**
     * Pobíraný důchod z přihlášky (`pens`) se propíše do zákonné evidence osoby,
     * odkud ho čte ELDP i JMHZ. Do vyplněné evidence import nesahá.
     */
    public function testRegistrationPensionFillsEmptyStatutoryEvidenceOnly(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1962-04-03', 'male', 3);
        $files = [$this->file('a1-pens.xml', RegistrationXmlFixtures::regzecA1([
            'bno' => $birthNumber,
            'birth_date' => '1962-04-03',
            'sex' => 'M',
            'first' => 'Karel',
            'last' => 'Zkušební',
            'pens' => '<pens typ="1" tak="2025-11-12" early="A"/>',
        ]))];

        $record = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertContains('pension', array_column($record['changes'], 'field'), json_encode($record, JSON_UNESCAPED_UNICODE));
        $applied = $this->apply($files, [$record['key']]);
        $result = $applied['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertContains('pension', $result['operations'], (string) $result['message']);

        $employeeId = (int) $result['employee_id'];
        $pension = $this->row(
            'SELECT pension_type_code, early_retirement, reduced_retirement_age, effective_from, effective_to
               FROM payroll_person_pensions WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $employeeId],
        );
        self::assertSame(['1', 1, 0, '2025-11-12', null], [
            (string) $pension['pension_type_code'],
            (int) $pension['early_retirement'],
            (int) $pension['reduced_retirement_age'],
            (string) $pension['effective_from'],
            $pension['effective_to'],
        ]);

        // Jiný důchod v další větě vyplněnou evidenci nepřepíše, jen ho ohlásí.
        $changed = [$this->file('a1-pens-2.xml', RegistrationXmlFixtures::regzecA1([
            'bno' => $birthNumber,
            'birth_date' => '1962-04-03',
            'sex' => 'M',
            'first' => 'Karel',
            'last' => 'Zkušební',
            'pens' => '<pens typ="8" tak="2026-01-01"/>',
        ]))];
        $again = $this->imports->preview($this->supplierId, 'test', $changed)['records'][0];
        self::assertNotContains('pension', array_column($again['changes'], 'field'));
        self::assertNotEmpty(array_filter(
            $again['warnings'],
            static fn (string $warning): bool => str_contains($warning, 'Pobíraný důchod'),
        ));
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_person_pensions WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $employeeId],
        ));
    }

    public function testExistingPersonGetsInsurerChangeFromRegistration(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $original = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber]))];
        $created = $this->apply($original, [$this->imports->preview($this->supplierId, 'test', $original)['records'][0]['key']]);
        $employeeId = (int) $created['results'][0]['employee_id'];

        $changed = [$this->file('a1-ozp.xml', RegistrationXmlFixtures::regzecA1([
            'bno' => $birthNumber,
            'insurer' => '207',
        ]))];
        $preview = $this->imports->preview($this->supplierId, 'test', $changed);
        $record = $preview['records'][0];
        self::assertSame('update', $record['operation'], json_encode($record, JSON_UNESCAPED_UNICODE));
        $insurerChange = array_values(array_filter(
            $record['changes'],
            static fn (array $change): bool => $change['field'] === 'health_insurer_code',
        ));
        self::assertSame([['field' => 'health_insurer_code', 'label' => 'Zdravotní pojišťovna', 'current' => '111', 'imported' => '207']], $insurerChange);

        $applied = $this->apply($changed, [$record['key']]);
        self::assertSame('applied', $applied['results'][0]['status'], (string) $applied['results'][0]['message']);
        self::assertContains('health_insurer', $applied['results'][0]['operations']);
        self::assertSame('207', $this->lookup->healthInsurerAt($this->supplierId, $employeeId, '2026-07-01'));
        self::assertSame(1, $this->tableRows('payroll_employees'));
    }

    public function testDeregistrationEndsEmploymentAndAssignsIdentifiers(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $a1 = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber]))];
        $created = $this->apply($a1, [$this->imports->preview($this->supplierId, 'test', $a1)['records'][0]['key']]);
        $employeeId = (int) $created['results'][0]['employee_id'];
        $employmentId = (int) $created['results'][0]['employment_id'];

        $oic = RegistrationXmlFixtures::oic(7);
        $idPpv = '200000000000000000101';
        $a2 = [$this->file('a2.xml', RegistrationXmlFixtures::regzecA2($birthNumber, $oic, $idPpv, '2026-08-31'))];
        $preview = $this->imports->preview($this->supplierId, 'test', $a2);
        $record = $preview['records'][0];
        self::assertSame('terminate', $record['operation'], json_encode($record, JSON_UNESCAPED_UNICODE));
        self::assertSame($employmentId, $record['match']['employment_id']);
        self::assertTrue($record['person']['has_oic']);
        self::assertTrue($record['employment']['has_id_ppv']);
        self::assertSame(1, $preview['summary']['terminate']);

        $applied = $this->apply($a2, [$record['key']]);
        $result = $applied['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertNull($result['message'], (string) $result['message']);
        self::assertSame(['terminated', 'identifiers'], $result['operations']);
        $employment = $this->lookup->employment($this->supplierId, $employmentId);
        self::assertSame('ended', $employment['status']);
        self::assertSame('2026-08-31', $employment['end_date']);

        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        self::assertTrue($identities->activePersonExternalIdMatches($this->supplierId, $employeeId, 'test', $oic));
        self::assertTrue($identities->activeEmploymentExternalIdMatches($this->supplierId, $employmentId, 'test', $idPpv));
        self::assertSame('2026-07-01', $this->scalar(
            'SELECT valid_from FROM payroll_employment_external_ids WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        ));

        $again = $this->imports->preview($this->supplierId, 'test', $a2)['records'][0];
        self::assertSame('none', $again['operation'], json_encode($again, JSON_UNESCAPED_UNICODE));
        self::assertSame('oic', $again['match']['matched_by']);
    }

    public function testPreRegistrationCreatesPlannedPersonWithoutActivation(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1985-03-04', 'female', 2);
        $files = [$this->file('p1.xml', RegistrationXmlFixtures::prezecP1($birthNumber, 'Petra', 'Nováková', '2026-12-01'))];

        $record = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertSame('PREZEC26', $record['document_type']);
        self::assertSame(9, $record['action_code']);
        self::assertSame('create_person', $record['operation'], json_encode($record, JSON_UNESCAPED_UNICODE));
        self::assertSame('employment', $record['employment']['relation_type']);

        $result = $this->apply($files, [$record['key']])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertNotContains('activated', $result['operations']);
        $employment = $this->lookup->employment($this->supplierId, (int) $result['employment_id']);
        self::assertSame('planned', $employment['status']);
        self::assertSame('2026-12-01', $employment['start_date']);
        self::assertSame('Testov', $this->scalar(
            'SELECT birth_place FROM payroll_person_identity_history WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, (int) $result['employee_id']],
        ));
    }

    public function testTwoOpenEmploymentsMakeChangeAmbiguousAndBlocked(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $a1 = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber]))];
        $this->apply($a1, [$this->imports->preview($this->supplierId, 'test', $a1)['records'][0]['key']]);

        $p1 = [$this->file('p1.xml', RegistrationXmlFixtures::prezecP1($birthNumber, 'Jana', 'Testovací', '2026-10-01'))];
        $second = $this->imports->preview($this->supplierId, 'test', $p1)['records'][0];
        self::assertSame('create_employment', $second['operation'], json_encode($second, JSON_UNESCAPED_UNICODE));
        $applied = $this->apply($p1, [$second['key']])['results'][0];
        self::assertSame(['employment_created'], array_slice($applied['operations'], 0, 1), (string) $applied['message']);
        self::assertSame(2, $this->tableRows('payroll_employments'));

        $a3 = [$this->file('a3.xml', RegistrationXmlFixtures::regzecA3($birthNumber, '2026-09-01'))];
        $record = $this->imports->preview($this->supplierId, 'test', $a3)['records'][0];
        self::assertSame('ambiguous', $record['match']['status']);
        self::assertCount(2, $record['match']['candidates']);
        self::assertNotNull($record['blocker']);
        self::assertFalse($record['selectable']);

        $result = $this->apply($a3, [$record['key']])['results'][0];
        self::assertSame('skipped', $result['status']);
        self::assertSame($record['blocker'], $result['message']);
    }

    /**
     * Soubor s jednou větou mimo schéma: věta se zablokuje s důvodem
     * u souboru, ostatní věty se převezmou (ČSSZ dávku zpracuje po větách).
     */
    public function testSentenceOutsideSchemaIsBlockedWhileTheRestOfTheFileIsImported(): void
    {
        $files = [$this->file('davka.xml', RegistrationXmlFixtures::twoA1Sentences(true, false))];

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        self::assertNull($preview['files'][0]['error']);
        self::assertSame(1, $preview['files'][0]['record_count']);
        self::assertCount(1, $preview['files'][0]['warnings']);
        self::assertStringContainsString('Věta 1 (sqnr 1)', $preview['files'][0]['warnings'][0]);
        self::assertStringContainsString('nepřebírá', $preview['files'][0]['warnings'][0]);
        self::assertCount(1, $preview['records']);
        $record = $preview['records'][0];
        self::assertSame('create_person', $record['operation'], json_encode($record, JSON_UNESCAPED_UNICODE));

        $applied = $this->apply($files, [$record['key']]);
        self::assertSame('applied', $applied['results'][0]['status'], (string) $applied['results'][0]['message']);
        self::assertSame(1, $this->tableRows('payroll_employees'));
    }

    public function testUnreadableFilesAreReportedWhileOthersAreProcessed(): void
    {
        $doctype = "<?xml version=\"1.0\"?>\n<!DOCTYPE x [<!ENTITY e \"x\">]>\n<REGZEC xmlns=\"http://schemas.cssz.cz/REGZEC/2025\"/>";
        $preview = $this->imports->preview($this->supplierId, 'test', [
            $this->file('ok.xml', RegistrationXmlFixtures::regzecA1()),
            $this->file('faktura.xml', '<?xml version="1.0"?><invoice xmlns="urn:example:invoice"/>'),
            $this->file('doctype.xml', $doctype),
            $this->file('bez-okresu.xml', str_replace(' dep="111"', '', RegistrationXmlFixtures::regzecA1([
                'bno' => RegistrationXmlFixtures::birthNumber('1980-05-06', 'male', 3),
            ]))),
        ]);

        self::assertNull($preview['files'][0]['error']);
        self::assertStringContainsString('REGZEC25 ani PREZEC26', (string) $preview['files'][1]['error']);
        self::assertStringContainsString('DOCTYPE', (string) $preview['files'][2]['error']);
        self::assertStringContainsString('neodpovídá schématu', (string) $preview['files'][3]['error']);
        self::assertCount(1, $preview['records']);
        self::assertSame(1, $preview['summary']['total']);
    }

    public function testActionPreviewsAndRefusesUnconfirmedApply(): void
    {
        $action = $this->container->get(PayrollRegistrationImportAction::class);
        $files = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1())];

        $preview = $action->preview($this->request(['environment' => 'test', 'files' => $files]), new Response());
        if ($preview->getStatusCode() === 403) {
            self::markTestSkipped('Mzdový modul není v téhle instalaci licencovaný.');
        }
        self::assertSame(200, $preview->getStatusCode(), (string) $preview->getBody());
        $body = $this->json($preview);
        self::assertSame('test', $body['environment']);
        self::assertSame('create_person', $body['records'][0]['operation']);
        self::assertArrayNotHasKey('_steps', $body['records'][0]);

        $refused = $action->apply($this->request([
            'environment' => 'test',
            'files' => $files,
            'keys' => [$body['records'][0]['key']],
            'evidence_confirmed' => false,
        ]), new Response());
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(0, $this->tableRows('payroll_employees'));
    }

    /**
     * @param list<array{name:string,content_base64:string}> $files
     * @param list<string> $keys
     * @return array{results:list<array<string,mixed>>,summary:array<string,int>}
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
            'registration-import-test',
        );
    }

    /** @return array{name:string,content_base64:string} */
    private function file(string $name, string $content): array
    {
        return ['name' => $name, 'content_base64' => base64_encode($content)];
    }

    private function sensitive(): PayrollSensitiveData
    {
        return $this->container->get(PayrollSensitiveData::class);
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

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>
     */
    private function row(string $sql, array $params): array
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    /** @param array<string,mixed> $body */
    private function request(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payroll/imports/registrations/preview')
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
