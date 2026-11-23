<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Registration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportService;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\JmhzReportFixtures;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\RegistrationXmlFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Import REGZEC/PREZEC proti normě (audit podání mezd 10/2026, balík F): cizí firma,
 * souběžné vztahy, nové verze podmínek, přihláška + nenastoupení v jedné dávce, starší
 * věty než evidence, VČP, úmrtí, vztah bez příznaku ZMR, bližší určení vztahu, nesoulad
 * rodného čísla a platnost identifikátorů od nástupu. Jen syntetická data.
 */
#[Group('integration')]
final class RegistrationImportNormativeTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const OID_ONE = '200000000000000000101';
    private const OID_TWO = '200000000000000000202';

    private Connection $db;
    private ContainerInterface $container;
    private RegistrationImportService $imports;
    private RegistrationImportLookup $lookup;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 6) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje - test vyžaduje DB.');
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
            'registration-normative-' . bin2hex(random_bytes(4)) . '@invalid.example',
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

    /** IMP-01: soubor jiného zaměstnavatele (cizí VS) se u REGZEC nepřevezme. */
    public function testRegistrationOfForeignEmployerIsBlocked(): void
    {
        $files = [$this->file('cizi.xml', RegistrationXmlFixtures::regzecA1(['vs' => '9999999999']))];

        $record = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertNotNull($record['blocker'], json_encode($record, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('9999999999', (string) $record['blocker']);
        self::assertFalse($record['selectable']);

        $result = $this->apply($files, [$record['key']])['results'][0];
        self::assertSame('skipped', $result['status']);
        self::assertSame(0, $this->tableRows('payroll_employees'));
    }

    /** IMP-01: věta o změně VS nese starý i nový symbol, stačí, aby jeden patřil firmě. */
    public function testNewVariableSymbolOfOwnEmployerIsAccepted(): void
    {
        $files = [$this->file('zmena-vs.xml', RegistrationXmlFixtures::regzecA1(['vs' => '9999999999', 'nvs' => '1234567890']))];

        $record = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertNull($record['blocker'], json_encode($record, JSON_UNESCAPED_UNICODE));
        self::assertTrue($record['selectable']);
    }

    /** IMP-01: firma bez jediného VS se ověřit nedá, věta projde s varováním (jako hlášení JMHZ). */
    public function testEmployerWithoutSymbolOnlyWarns(): void
    {
        $this->db->pdo()->prepare('UPDATE payroll_offices SET social_security_variable_symbol = NULL WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $files = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1())];

        $record = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertNull($record['blocker'], json_encode($record, JSON_UNESCAPED_UNICODE));
        self::assertTrue($this->hasWarning($record, 'Firma nemá vyplněný VS'));
    }

    /** IMP-02: druhý souběžný vztah téhož druhu s jiným ID PPV se neslije s prvním. */
    public function testConcurrentRelationshipWithAnotherIdPpvCreatesNewEmployment(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $first = [$this->file('a1-1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber, 'oid' => self::OID_ONE]))];
        $created = $this->apply($first, [$this->previewKey($first)])['results'][0];
        self::assertSame('applied', $created['status'], (string) $created['message']);
        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        self::assertTrue($identities->activeEmploymentExternalIdMatches(
            $this->supplierId,
            (int) $created['employment_id'],
            'test',
            self::OID_ONE,
        ));

        $second = [$this->file('a1-2.xml', RegistrationXmlFixtures::regzecA1([
            'bno' => $birthNumber,
            'oid' => self::OID_TWO,
            'start' => '2026-09-01',
        ]))];
        $record = $this->imports->preview($this->supplierId, 'test', $second)['records'][0];
        self::assertSame('create_employment', $record['operation'], json_encode($record, JSON_UNESCAPED_UNICODE));
        self::assertNull($record['match']['employment_id']);

        $result = $this->apply($second, [$record['key']])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertSame(2, $this->tableRows('payroll_employments'));
        self::assertNotSame((int) $created['employment_id'], (int) $result['employment_id']);
    }

    /** IMP-03: změna z věty A3 se do podmínek nezapíše zpětně, ale jako nová verze od 1. dne měsíce. */
    public function testChangeFromTermsIsWrittenAsNewVersionFromMonthStart(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $a1 = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber]))];
        $employmentId = (int) $this->apply($a1, [$this->previewKey($a1)])['results'][0]['employment_id'];

        $a3 = [$this->file('a3.xml', RegistrationXmlFixtures::regzecA3($birthNumber, '2026-09-15', ['place' => 'Brno', 'municode' => '582786', 'insurer' => null]))];
        $record = $this->imports->preview($this->supplierId, 'test', $a3)['records'][0];
        self::assertSame('update', $record['operation'], json_encode($record, JSON_UNESCAPED_UNICODE));
        $result = $this->apply($a3, [$record['key']])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertNull($result['message'], (string) $result['message']);

        $rows = $this->rows(
            'SELECT effective_from, effective_to, work_place FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ? ORDER BY effective_from',
            [$this->supplierId, $employmentId],
        );
        self::assertSame(
            [
                ['2026-07-01', '2026-08-31', 'Hlavní město Praha'],
                ['2026-09-01', null, 'Brno'],
            ],
            array_map('array_values', $rows),
            'Dřívější podmínky zůstávají, změna platí od 1. 9. jako nová verze.',
        );
    }

    public function testRegistrationChangeKeepsHourlyRemunerationInNewTerms(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $a1 = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber]))];
        $employmentId = (int) $this->apply($a1, [$this->previewKey($a1)])['results'][0]['employment_id'];
        $this->db->pdo()->prepare(
            "UPDATE payroll_employment_terms SET remuneration_type = 'hourly', hourly_rate_minor = 20000, remuneration_basis_kind = 'wage_statement',
                    remuneration_basis = 'Syntetický výměr', monthly_gross_minor = NULL
              WHERE supplier_id = ? AND employment_id = ?",
        )->execute([$this->supplierId, $employmentId]);

        $a3 = [$this->file('a3.xml', RegistrationXmlFixtures::regzecA3($birthNumber, '2026-09-15', [
            'place' => 'Brno', 'municode' => '582786', 'insurer' => null,
        ]))];
        $result = $this->apply($a3, [$this->previewKey($a3)])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        $rows = $this->rows(
            'SELECT remuneration_type, hourly_rate_minor, remuneration_basis, monthly_gross_minor
               FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ? ORDER BY effective_from',
            [$this->supplierId, $employmentId],
        );
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame('hourly', $row['remuneration_type']);
            self::assertSame(20000, (int) $row['hourly_rate_minor']);
            self::assertSame('Syntetický výměr', $row['remuneration_basis']);
            self::assertNull($row['monthly_gross_minor']);
        }
    }

    /** IMP-04: přihláška v minulosti + nenastoupení téže osoby v jedné dávce skončí jako nenastoupený vztah. */
    public function testRegistrationAndNoShowInOneBatchEndsAsNoShow(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $files = [
            $this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber])),
            $this->file('a8.xml', RegistrationXmlFixtures::regzecA8($birthNumber)),
        ];

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        [$registration, $noShow] = $preview['records'];
        self::assertSame(1, $registration['action_code']);
        self::assertSame(8, $noShow['action_code']);
        self::assertNull($noShow['blocker'], json_encode($noShow, JSON_UNESCAPED_UNICODE));
        self::assertTrue($noShow['selectable']);

        $applied = $this->apply($files, [$registration['key'], $noShow['key']]);
        foreach ($applied['results'] as $result) {
            self::assertSame('applied', $result['status'], (string) $result['message']);
        }
        self::assertSame('complete', $applied['outcome'], json_encode($applied['unresolved'], JSON_UNESCAPED_UNICODE));
        $employmentId = (int) $applied['results'][0]['employment_id'];
        self::assertSame('no_show', $this->lookup->employment($this->supplierId, $employmentId)['status'] ?? null);
    }

    /** IMP-04: nezapsané nenastoupení nenechá výsledek vypadat jako hotový, vztah zůstane plánovaný. */
    public function testResultIsIncompleteWhenNoShowOfTheBatchIsNotWritten(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $noShow = str_replace('vs="1234567890"', 'vs="9999999999"', RegistrationXmlFixtures::regzecA8($birthNumber));
        $files = [
            $this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber])),
            $this->file('a8.xml', $noShow),
        ];
        [$registration, $cancellation] = $this->imports->preview($this->supplierId, 'test', $files)['records'];
        self::assertNotNull($cancellation['blocker']);

        $applied = $this->apply($files, [$registration['key'], $cancellation['key']]);

        self::assertSame('applied', $applied['results'][0]['status'], (string) $applied['results'][0]['message']);
        self::assertSame('skipped', $applied['results'][1]['status']);
        self::assertSame('incomplete', $applied['outcome']);
        self::assertSame([$registration['key']], array_column($applied['unresolved'], 'key'));
        self::assertSame('planned', $this->lookup->employment($this->supplierId, (int) $applied['results'][0]['employment_id'])['status'] ?? null);
    }

    /** IMP-04: částečné přihlášení (P1) + ukončení předregistrace (P2) v jedné dávce. */
    public function testPreRegistrationAndItsCancellationInOneBatch(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1985-03-04', 'female', 2);
        $files = [
            $this->file('p1.xml', RegistrationXmlFixtures::prezecP1($birthNumber, 'Petra', 'Nováková', '2026-12-01')),
            $this->file('p2.xml', RegistrationXmlFixtures::prezecP2($birthNumber)),
        ];

        $records = $this->imports->preview($this->supplierId, 'test', $files)['records'];
        self::assertSame([9, 10], array_column($records, 'action_code'));
        self::assertNull($records[1]['blocker'], json_encode($records[1], JSON_UNESCAPED_UNICODE));
        self::assertTrue($records[1]['selectable']);

        $applied = $this->apply($files, [$records[0]['key'], $records[1]['key']]);
        foreach ($applied['results'] as $result) {
            self::assertSame('applied', $result['status'], (string) $result['message']);
        }
        self::assertSame('no_show', $this->lookup->employment($this->supplierId, (int) $applied['results'][0]['employment_id'])['status'] ?? null);
    }

    /** IMP-04: když vybereme jen přihlášku, aktivuje se jako dřív. */
    public function testRegistrationAloneStillActivates(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $files = [
            $this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber])),
            $this->file('a8.xml', RegistrationXmlFixtures::regzecA8($birthNumber)),
        ];
        $registration = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];

        $result = $this->apply($files, [$registration['key']])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertSame('active', $this->lookup->employment($this->supplierId, (int) $result['employment_id'])['status'] ?? null);
    }

    /** IMP-05: starší věta než evidence nepřepíše historii pojišťovny. */
    public function testStaleRegistrationDoesNotRewriteInsurerHistory(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $a1 = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber]))];
        $employeeId = (int) $this->apply($a1, [$this->previewKey($a1)])['results'][0]['employee_id'];
        $change = [$this->file('a3-nove.xml', RegistrationXmlFixtures::regzecA3($birthNumber, '2026-09-01', ['insurer' => '207']))];
        $applied = $this->apply($change, [$this->previewKey($change)])['results'][0];
        self::assertSame('applied', $applied['status'], (string) $applied['message']);
        self::assertSame('207', $this->lookup->healthInsurerAt($this->supplierId, $employeeId, '2026-09-15'));
        self::assertSame('111', $this->lookup->healthInsurerAt($this->supplierId, $employeeId, '2026-08-15'));

        $stale = [$this->file('a3-stare.xml', RegistrationXmlFixtures::regzecA3($birthNumber, '2026-08-10', ['insurer' => '201']))];
        $record = $this->imports->preview($this->supplierId, 'test', $stale)['records'][0];
        self::assertTrue($this->hasWarning($record, 'starší než evidence'), json_encode($record, JSON_UNESCAPED_UNICODE));
        foreach ($record['changes'] as $change) {
            self::assertNotSame('health_insurer_code', $change['field']);
        }

        $this->apply($stale, [$record['key']]);
        self::assertSame('111', $this->lookup->healthInsurerAt($this->supplierId, $employeeId, '2026-08-15'));
        self::assertSame('207', $this->lookup->healthInsurerAt($this->supplierId, $employeeId, '2026-09-15'));
    }

    /** IMP-05: stejně starší věta nepřepíše adresu, která se od jejího dne změnila. */
    public function testStaleRegistrationDoesNotOverwriteNewerAddress(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $a1 = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber]))];
        $employeeId = (int) $this->apply($a1, [$this->previewKey($a1)])['results'][0]['employee_id'];
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "UPDATE payroll_person_addresses SET effective_to = '2026-08-31'
              WHERE supplier_id = ? AND employee_id = ? AND address_type = 'residence'"
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            "INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city, postal_code, country_code, effective_from)
             VALUES (?, ?, 'residence', 'Nová 7', 'Brno', '60200', 'CZ', '2026-09-01')"
        )->execute([$this->supplierId, $employeeId]);

        $stale = [$this->file('a1-stara.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber, 'street' => 'Stará', 'num' => '1']))];
        $record = $this->imports->preview($this->supplierId, 'test', $stale)['records'][0];
        self::assertTrue($this->hasWarning($record, 'starší než evidence'), json_encode($record, JSON_UNESCAPED_UNICODE));
        foreach ($record['changes'] as $change) {
            self::assertNotSame('residence_address', $change['field']);
        }

        $result = $this->apply($stale, [$record['key']])['results'][0];
        self::assertNotContains('address', $result['operations']);
        self::assertSame('Zkušební 12', $this->lookup->addressAt($this->supplierId, $employeeId, 'residence', '2026-07-15')['street_line'] ?? null);
        self::assertSame('Nová 7', $this->lookup->addressAt($this->supplierId, $employeeId, 'residence', '2026-09-15')['street_line'] ?? null);
    }

    /** IMP-06: `ona` je dřívější příjmení - ukáže se jako nápověda, rodné příjmení se nemění. */
    public function testFormerSurnameIsOnlyAHint(): void
    {
        $files = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['ona' => 'Dřívější']))];

        $record = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertTrue($this->hasWarning($record, 'Dřívější'), json_encode($record['warnings'], JSON_UNESCAPED_UNICODE));
        $result = $this->apply($files, [$record['key']])['results'][0];
        $identity = $this->row(
            'SELECT last_name, birth_surname FROM payroll_person_identity_history WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, (int) $result['employee_id']],
        );
        self::assertSame(['Testovací', 'Zkušební'], array_values($identity));
    }

    /**
     * `ona` bez data změny do historie jména nepatří: věta by tím vymyslela
     * platnost verze identity. Import řekne, co a kde doplnit ručně, a historii
     * nezakládá.
     */
    public function testFormerSurnameWithoutEvidenceNamesTheManualStepAndWritesNoHistory(): void
    {
        $files = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['ona' => 'Dřívější']))];

        $record = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        $warning = $this->warningContaining($record, 'Dřívější');
        self::assertStringContainsString('evidence žádné dřívější příjmení osoby nevede', $warning);
        self::assertStringContainsString('Historie jména', $warning);
        self::assertStringContainsString('datum', $warning);

        $result = $this->apply($files, [$record['key']])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_person_identity_history WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, (int) $result['employee_id']],
        ));
    }

    public function testFormerSurnameAlreadyInHistoryIsNotReportedAgain(): void
    {
        $employeeId = $this->importedPersonWithEarlierSurname('Dřívější');
        self::assertGreaterThan(0, $employeeId);

        $files = [$this->file('a1-ona.xml', RegistrationXmlFixtures::regzecA1(['ona' => 'Dřívější']))];
        $record = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];

        self::assertSame('matched', $record['match']['status'], json_encode($record, JSON_UNESCAPED_UNICODE) ?: '');
        foreach ($record['warnings'] as $warning) {
            self::assertStringNotContainsString('dřívější příjmení', (string) $warning);
        }
    }

    public function testFormerSurnameDifferentFromHistoryNamesBoth(): void
    {
        $this->importedPersonWithEarlierSurname('Jiné');

        $files = [$this->file('a1-ona.xml', RegistrationXmlFixtures::regzecA1(['ona' => 'Dřívější']))];
        $record = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];

        $warning = $this->warningContaining($record, 'Dřívější');
        self::assertStringContainsString('vede: Jiné', $warning);
    }

    /** Založí osobu z A1 a před její aktuální verzi identity vloží starší s daným příjmením. */
    private function importedPersonWithEarlierSurname(string $surname): int
    {
        $files = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1())];
        $result = $this->apply($files, [$this->previewKey($files)])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        $employeeId = (int) $result['employee_id'];
        $current = $this->row(
            'SELECT effective_from FROM payroll_person_identity_history WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $employeeId],
        );
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name, effective_from, effective_to)
             VALUES (?, ?, ?, "Jana", ?, "1900-01-01", DATE_SUB(?, INTERVAL 1 DAY))',
        )->execute([$this->supplierId, $employeeId, 'Jana ' . $surname, $surname, $current['effective_from']]);

        return $employeeId;
    }

    /** @param array<string,mixed> $record */
    private function warningContaining(array $record, string $needle): string
    {
        foreach ($record['warnings'] as $warning) {
            if (str_contains((string) $warning, $needle)) {
                return (string) $warning;
            }
        }
        self::fail("Varování s „{$needle}“ chybí: " . json_encode($record['warnings'], JSON_UNESCAPED_UNICODE));
    }

    /** IMP-07: VČP se přečte, zapíše při založení a podle něj se osoba najde i bez rodného čísla. */
    public function testVcpIsWrittenAndMatchesPersonWithoutBirthNumber(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $a1 = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber, 'vcp' => '612345678']))];
        $result = $this->apply($a1, [$this->previewKey($a1)])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertContains('vcp', $result['operations']);
        $employeeId = (int) $result['employee_id'];
        self::assertSame([$employeeId], $this->lookup->employeesByIdentifierHash(
            $this->supplierId,
            'vcp',
            $this->sensitive()->lookupHash('612345678', PayrollSensitiveField::PERSONAL_IDENTIFIER, $this->supplierId),
        ));

        $a3 = [$this->file('a3.xml', RegistrationXmlFixtures::regzecA3(null, '2026-09-01', ['vcp' => '612345678']))];
        $record = $this->imports->preview($this->supplierId, 'test', $a3)['records'][0];
        self::assertSame('matched', $record['match']['status'], json_encode($record, JSON_UNESCAPED_UNICODE));
        self::assertSame('vcp', $record['match']['matched_by']);
        self::assertSame($employeeId, $record['match']['employee_id']);
    }

    /** IMP-07: vadné VČP se nepřevezme a účetní se to dozví. */
    public function testInvalidVcpIsReportedAndSkipped(): void
    {
        $files = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['vcp' => '123456789']))];

        $record = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertTrue($this->hasWarning($record, 'VČP ve větě není platné'));
        foreach ($record['changes'] as $change) {
            self::assertNotSame('vcp', $change['field']);
        }
    }

    /** IMP-08: skončení úmrtím se zapíše jako způsob skončení a kód důvodu pro úřad práce se oznámí. */
    public function testDeregistrationByDeathIsRecordedAndReasonCodeReported(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $a1 = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber]))];
        $employmentId = (int) $this->apply($a1, [$this->previewKey($a1)])['results'][0]['employment_id'];

        $a2 = [$this->file('a2.xml', RegistrationXmlFixtures::regzecA2(
            $birthNumber,
            RegistrationXmlFixtures::oic(7),
            self::OID_ONE,
            '2026-08-31',
            ['endbydeath' => 'A', 'rsnterempl' => '15'],
        ))];
        $record = $this->imports->preview($this->supplierId, 'test', $a2)['records'][0];
        self::assertSame('terminate', $record['operation'], json_encode($record, JSON_UNESCAPED_UNICODE));
        self::assertTrue($this->hasWarning($record, 'úmrtím'));
        self::assertTrue($this->hasWarning($record, 'kód důvodu ukončení 15'));

        $result = $this->apply($a2, [$record['key']])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertContains('termination_reason', $result['operations']);
        self::assertSame('death', $this->scalar(
            'SELECT termination_method FROM payroll_employment_terminations WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        ));
    }

    /** IMP-09: odhláška zaměstnání malého rozsahu bez příznaku `sme` najde svůj vztah. */
    public function testDeregistrationWithoutSmallScaleFlagMatchesSmallScaleEmployment(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $a1 = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber, 'rel' => '2', 'sme' => 'A']))];
        $created = $this->apply($a1, [$this->previewKey($a1)])['results'][0];
        self::assertSame('applied', $created['status'], (string) $created['message']);
        $employmentId = (int) $created['employment_id'];
        self::assertSame('small_scale_employment', $this->lookup->employment($this->supplierId, $employmentId)['relation_type'] ?? null);

        $a2 = [$this->file('a2.xml', RegistrationXmlFixtures::regzecA2(
            $birthNumber,
            RegistrationXmlFixtures::oic(7),
            self::OID_ONE,
            '2026-08-31',
            ['rel' => '2'],
        ))];
        $record = $this->imports->preview($this->supplierId, 'test', $a2)['records'][0];
        self::assertNull($record['blocker'], json_encode($record, JSON_UNESCAPED_UNICODE));
        self::assertSame($employmentId, $record['match']['employment_id']);

        $result = $this->apply($a2, [$record['key']])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertSame('ended', $this->lookup->employment($this->supplierId, $employmentId)['status'] ?? null);
    }

    /**
     * IMP-10: bližší určení vztahu 3 (specifická skupina) evidence u pracovního
     * poměru nevede, tichá změna na 1 se musí ohlásit. Určení 2 (výkon trestu,
     * formulář vězně) evidence vede a převezme ho beze změny.
     */
    public function testRelationshipDetailOtherThanOneIsReported(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $a1 = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber]))];
        $this->apply($a1, [$this->previewKey($a1)]);

        $a3 = [$this->file('a3.xml', RegistrationXmlFixtures::regzecA3($birthNumber, '2026-09-01', ['relDetail' => '3', 'insurer' => null]))];
        $record = $this->imports->preview($this->supplierId, 'test', $a3)['records'][0];
        self::assertTrue($this->hasWarning($record, 'Bližší určení vztahu „3“'), json_encode($record['warnings'], JSON_UNESCAPED_UNICODE));

        $prisoner = [$this->file('a3-vezen.xml', RegistrationXmlFixtures::regzecA3($birthNumber, '2026-09-01', ['relDetail' => '2', 'insurer' => null]))];
        $kept = $this->imports->preview($this->supplierId, 'test', $prisoner)['records'][0];
        self::assertFalse($this->hasWarning($kept, 'Bližší určení vztahu'), json_encode($kept['warnings'], JSON_UNESCAPED_UNICODE));
        self::assertContains(
            '2',
            array_column($kept['changes'], 'imported'),
            json_encode($kept['changes'], JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Registrace s druhem činnosti 11 až 14 (jiný příjem, pronájem síly) se
     * zapíše jako pracovní poměr; dřív ji import odmítl, protože druh vztahu
     * k takovému kódu neuměl přiřadit.
     */
    public function testOtherIncomeActivityRegistrationCreatesEmployment(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1985-03-10', 'male', 3);
        $a1 = [$this->file('a1-13.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber, 'rel' => '13', 'sex' => 'M']))];
        $record = $this->imports->preview($this->supplierId, 'test', $a1)['records'][0];
        self::assertNull($record['blocker'], json_encode($record, JSON_UNESCAPED_UNICODE));
        self::assertSame('employment', $record['employment']['relation_type']);

        $result = $this->apply($a1, [$record['key']])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertSame('13', $this->scalar(
            'SELECT activity_code FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, (int) $result['employment_id']],
        ));
    }

    /** IMP-11: osoba nalezená podle OIČ s jiným rodným číslem ve větě dostane varování. */
    public function testDifferentBirthNumberOnPersonMatchedByOicWarns(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $oic = RegistrationXmlFixtures::oic(7);
        $a1 = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber]))];
        $this->apply($a1, [$this->previewKey($a1)]);
        $assign = [$this->file('a3-oic.xml', RegistrationXmlFixtures::regzecA3($birthNumber, '2026-07-02', ['ikmpsv' => $oic, 'oid' => self::OID_ONE, 'insurer' => null]))];
        $assigned = $this->apply($assign, [$this->previewKey($assign)])['results'][0];
        self::assertSame('applied', $assigned['status'], (string) $assigned['message']);

        $other = RegistrationXmlFixtures::birthNumber('1991-02-03', 'male', 4);
        $a3 = [$this->file('a3-jine.xml', RegistrationXmlFixtures::regzecA3($other, '2026-09-01', ['ikmpsv' => $oic, 'insurer' => '207']))];
        $record = $this->imports->preview($this->supplierId, 'test', $a3)['records'][0];
        self::assertSame('oic', $record['match']['matched_by'], json_encode($record, JSON_UNESCAPED_UNICODE));
        self::assertTrue($this->hasWarning($record, 'Rodné číslo ve větě se liší'), json_encode($record['warnings'], JSON_UNESCAPED_UNICODE));
    }

    /** IMP-13: OIČ a ID PPV z A3 platí od začátku vztahu, ne až od `fro` věty. */
    public function testIdentifiersFromChangeSentenceAreValidFromRelationshipStart(): void
    {
        $birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $a1 = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $birthNumber]))];
        $employmentId = (int) $this->apply($a1, [$this->previewKey($a1)])['results'][0]['employment_id'];

        $a3 = [$this->file('a3.xml', RegistrationXmlFixtures::regzecA3($birthNumber, '2026-09-15', [
            'ikmpsv' => RegistrationXmlFixtures::oic(7),
            'oid' => self::OID_ONE,
            'insurer' => null,
        ]))];
        $result = $this->apply($a3, [$this->previewKey($a3)])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertContains('identifiers', $result['operations']);

        self::assertSame('2026-07-01', $this->scalar(
            'SELECT valid_from FROM payroll_employment_external_ids WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        ));
    }

    /** PRE-06: balík JMHZ s 1500 formuláři (přes 5 MB) projde službou importu. */
    public function testLargeJmhzPackageIsNotRejectedBySizeLimit(): void
    {
        $people = [];
        for ($i = 1; $i <= 1500; $i++) {
            $people[] = JmhzReportFixtures::person([
                'employment_id' => $i,
                'oic' => RegistrationXmlFixtures::oic($i),
                'id_ppv' => sprintf('2%020d', $i),
            ]);
        }
        $xml = JmhzReportFixtures::report($people, 2026, 8);
        self::assertGreaterThan(5_000_000, strlen($xml));

        $preview = $this->imports->preview($this->supplierId, 'test', [$this->file('jmhz-1500.xml', $xml)]);

        self::assertSame('JMHZ', $preview['files'][0]['document_type']);
        self::assertSame(1500, $preview['files'][0]['record_count']);
    }

    /** @param list<array{name:string,content_base64:string}> $files */
    private function previewKey(array $files): string
    {
        $records = $this->imports->preview($this->supplierId, 'test', $files)['records'];
        self::assertNotEmpty($records);
        self::assertNull($records[0]['blocker'], json_encode($records[0], JSON_UNESCAPED_UNICODE));

        return (string) $records[0]['key'];
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
            'registration-normative-test',
        );
    }

    /** @return array{name:string,content_base64:string} */
    private function file(string $name, string $content): array
    {
        return ['name' => $name, 'content_base64' => base64_encode($content)];
    }

    /** @param array<string,mixed> $record */
    private function hasWarning(array $record, string $needle): bool
    {
        foreach ($record['warnings'] as $warning) {
            if (str_contains((string) $warning, $needle)) {
                return true;
            }
        }

        return false;
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

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
