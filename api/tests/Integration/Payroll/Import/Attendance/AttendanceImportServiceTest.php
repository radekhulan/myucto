<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Attendance;

use MyInvoice\Action\Payroll\PayrollAttendanceImportAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSourceConfirmationRequired;
use MyInvoice\Service\Payroll\PayrollEmploymentBulkClosure;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Import docházky nad izolovanou firmou v transakci (tearDown ji vrací).
 *
 * Jana se páruje přes rodné číslo, Petr přes jméno, Eva v evidenci chybí.
 * Úkolová mzda se v testech použití mapuje na složku UKOL_TEST, která ve firmě
 * není ani ve výchozím katalogu — její částky musí skončit jako chyby vstupní
 * brány, ne potichu zmizet.
 */
#[Group('integration')]
final class AttendanceImportServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = AttendanceFixture::PERIOD;
    private const MISSING_COMPONENT = 'UKOL_TEST';

    private Connection $db;
    private ContainerInterface $container;
    private AttendanceImportService $service;
    private int $supplierId;
    private int $userId;
    /** @var array{employee_id:int,employment_id:int} */
    private array $jana;
    /** @var array{employee_id:int,employment_id:int} */
    private array $petr;

    protected function setUp(): void
    {
        $this->container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $this->container->get(Connection::class);
        $service = $this->container->get(AttendanceImportService::class);
        if (!$db instanceof Connection || !$service instanceof AttendanceImportService) {
            throw new \RuntimeException('Služba importu docházky není dostupná.');
        }
        $this->db = $db;
        $this->service = $service;
        foreach ([
            'payroll_attendance_imports',
            'payroll_attendance_import_rows',
            'payroll_import_links',
            'payroll_import_profiles',
            'payroll_inputs',
            'payroll_person_identifiers',
        ] as $table) {
            if (!$db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->userId]);
        // Vztah bez mzdové účtárny nejde založit; doménová vrstva dosadí výchozí účtárnu zaměstnavatele.
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "IMP", "Syntetická účtárna", "1234567890", 1)',
        )->execute([$this->supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2026-01-01", "1234567890", "synthetic:attendance-import")',
        )->execute([$this->supplierId, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$this->supplierId, $officeId]);
        $this->jana = $this->createEmployment('Jana Testovací', 'ZAM-J');
        $this->petr = $this->createEmployment('Petr Zkušební', 'ZAM-P');
        $this->insertBirthNumber($this->jana['employee_id'], AttendanceFixture::janaBirthNumber());
        $this->createComponent('ODMENA');
        $this->createComponent('PREMIE_PRIPLATKY');
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

    public function testPreviewMatchesPersonsAndChecksComponents(): void
    {
        $files = AttendanceFixture::scenario();
        $preview = $this->service->preview($this->supplierId, self::PERIOD, $files, null, null);

        self::assertSame(3, $preview['summary']['persons']);
        self::assertSame(2, $preview['summary']['matched']);
        self::assertSame(1, $preview['summary']['not_found']);
        self::assertCount(2, $preview['employment_options']);
        self::assertCount(4, $preview['sheets']);
        self::assertSame([null, null, null], array_column($preview['files'], 'error'));

        $persons = $this->byKey($preview['persons']);
        self::assertSame('matched', $persons['jana testovaci']['match']['status']);
        self::assertSame('birth_number', $persons['jana testovaci']['match']['matched_by']);
        self::assertSame($this->jana['employment_id'], $persons['jana testovaci']['match']['employment_id']);
        self::assertSame('name', $persons['petr zkusebni']['match']['matched_by']);
        self::assertSame($this->petr['employment_id'], $persons['petr zkusebni']['match']['employment_id']);
        self::assertSame('not_found', $persons['eva pokusna']['match']['status']);

        $masked = (string) $persons['jana testovaci']['birth_number_masked'];
        $digits = (string) preg_replace('/\D/', '', AttendanceFixture::janaBirthNumber());
        self::assertNotSame('', $masked);
        self::assertStringNotContainsString(substr($digits, 0, 6), $masked);
        $json = json_encode($preview, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString(AttendanceFixture::janaBirthNumber(), $json);
        self::assertStringNotContainsString($digits, $json);
        foreach ($preview['persons'] as $person) {
            foreach (array_keys($person) as $key) {
                self::assertStringStartsNotWith('_', $key);
            }
        }

        // Úkolová mzda je ve výchozím katalogu složek, který vstupní brána
        // zakládá sama — náhled proto nesmí tvrdit, že chybí.
        $checks = array_column($preview['component_checks'], 'status', 'component_code');
        self::assertSame(['ODMENA' => 'ok', 'MZDA_UKOLOVA' => 'ok', 'PREMIE_PRIPLATKY' => 'ok'], $checks);

        $missing = $this->service->preview($this->supplierId, self::PERIOD, $files, $this->rules($files), null);
        $checks = array_column($missing['component_checks'], null, 'component_code');
        self::assertSame('missing', $checks[self::MISSING_COMPONENT]['status']);
        self::assertStringContainsString('Nastavení mezd', (string) $checks[self::MISSING_COMPONENT]['message']);
    }

    public function testApplyStoresBatchWithHoursAndCreatesInputProposals(): void
    {
        $files = AttendanceFixture::scenario();
        $result = $this->service->apply($this->supplierId, self::PERIOD, $files, $this->rules($files), [], true, true, $this->userId);

        self::assertFalse($result['replayed']);
        self::assertSame(2, $result['batch']['person_count']);
        self::assertSame(8, $result['batch']['metric_count']);
        self::assertSame(3, $result['batch']['input_count']);
        self::assertSame('attendance', $result['batch']['source_system']);
        self::assertSame(self::PERIOD, $result['batch']['period']);
        self::assertSame(['podklady.xlsx', 'provoz.xlsx', 'mzdy.csv'], array_column($result['batch']['files'], 'name'));
        self::assertSame(3, $result['inputs']['created']);
        self::assertSame(0, $result['inputs']['duplicates']);
        self::assertCount(2, $result['inputs']['errors']);
        foreach ($result['inputs']['errors'] as $error) {
            self::assertStringContainsString(self::MISSING_COMPONENT, $error['error_message']);
        }
        self::assertSame(2, $result['links_saved']);
        self::assertSame(['eva pokusna'], array_column($result['skipped_persons'], 'key'));

        self::assertSame(17, $this->countRows(
            'SELECT COUNT(*) FROM payroll_attendance_import_rows WHERE supplier_id = ? AND import_id = ?',
            [$this->supplierId, $result['batch']['id']],
        ));
        self::assertSame(3, $this->countRows('SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame(3, $this->countRows(
            'SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ? AND status = "draft" AND source_kind = "import"',
            [$this->supplierId],
        ), 'Vstupy z docházky zůstávají návrhy ke schválení.');
        self::assertSame(150000, $this->bonus($this->jana['employment_id']));

        $detail = $this->service->batch($this->supplierId, $result['batch']['id']);
        self::assertNotNull($detail);
        $worked = array_values(array_filter(
            $detail['rows'],
            fn (array $row): bool => $row['employment_id'] === $this->jana['employment_id'] && $row['meaning'] === 'worked_hours',
        ));
        self::assertSame('168.00', $worked[0]['hours']);
        self::assertSame('provoz.xlsx!vstup!B3', $worked[0]['source']);
        self::assertSame('Jana Testovací', $worked[0]['employee_name']);
        self::assertSame('ZAM-J', $worked[0]['employment_code']);

        self::assertCount(1, $this->service->batches($this->supplierId, self::PERIOD));
        self::assertCount(0, $this->service->batches($this->supplierId, '2026-05'));
    }

    public function testRepeatedApplyReturnsTheSameBatch(): void
    {
        $files = AttendanceFixture::scenario();
        $rules = $this->rules($files);
        $first = $this->service->apply($this->supplierId, self::PERIOD, $files, $rules, [], false, true, $this->userId);
        $second = $this->service->apply($this->supplierId, self::PERIOD, $files, $rules, [], false, true, $this->userId);

        self::assertTrue($second['replayed']);
        self::assertSame($first['batch']['id'], $second['batch']['id']);
        self::assertSame(0, $second['inputs']['created']);
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM payroll_attendance_imports WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame(3, $this->countRows('SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ?', [$this->supplierId]));
    }

    /**
     * Opravený soubor (jiná odměna) je nová dávka, ale odměna se nezaloží
     * podruhé — to by byla dvojí výplata. Stabilní external_id dovede import
     * k TÉMUŽ vstupu: dokud je koncept, oprava ho aktualizuje na místě,
     * nezměněné složky zůstávají duplicitami. Schválený vstup ani ruční přepis
     * v rychlém vstupu import nepřepíše (PayrollQuickInputComponentsTest).
     */
    public function testCorrectedFileDoesNotCreateSecondBonus(): void
    {
        $original = AttendanceFixture::scenario(1500);
        $corrected = AttendanceFixture::scenario(1800);
        $first = $this->service->apply($this->supplierId, self::PERIOD, $original, $this->rules($original), [], false, true, $this->userId);
        $second = $this->service->apply($this->supplierId, self::PERIOD, $corrected, $this->rules($corrected), [], false, true, $this->userId);

        self::assertFalse($second['replayed']);
        self::assertNotSame($first['batch']['id'], $second['batch']['id']);
        self::assertSame(0, $second['inputs']['created']);
        self::assertSame(2, $second['inputs']['duplicates']);
        self::assertSame(3, $this->countRows('SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ?', [$this->supplierId]));
        // Tentýž (jediný) vstup odměny nese opravenou částku.
        self::assertSame(180000, $this->bonus($this->jana['employment_id']));
        $detail = $this->container
            ->get(\MyInvoice\Repository\Payroll\PayrollInputImportRepository::class)
            ->detail($this->supplierId, (int) $second['inputs']['import_id']);
        self::assertIsArray($detail);
        self::assertSame(1, $detail['updated_count']);
    }

    /**
     * Noční příplatek 1 234,50 Kč by měsíční hlášení odmítlo až po schválení
     * mzdy. Volba importu ho zaokrouhlí nahoru na celé koruny; náhled ukáže
     * původní i zaokrouhlenou částku a vstup vznikne už zaokrouhlený.
     */
    public function testWholeCrownsOptionRoundsReportedComponentsUp(): void
    {
        $files = AttendanceFixture::scenario();
        $rules = $this->rules($files);

        $plain = $this->service->preview($this->supplierId, self::PERIOD, $files, $rules, null);
        self::assertTrue($plain['whole_crowns_default']);
        self::assertFalse($plain['round_whole_crowns']);
        self::assertSame(1, $plain['summary']['halere_components']);

        $preview = $this->service->preview($this->supplierId, self::PERIOD, $files, $rules, null, null, true);
        $components = array_column($this->byKey($preview['persons'])['jana testovaci']['components'], null, 'component_code');
        self::assertSame(123_500, $components['PREMIE_PRIPLATKY']['amount_minor']);
        self::assertSame(123_450, $components['PREMIE_PRIPLATKY']['rounded_from_minor']);
        self::assertArrayNotHasKey('rounded_from_minor', $components['ODMENA']);

        $this->service->apply(
            $this->supplierId, self::PERIOD, $files, $rules, [], false, true, $this->userId,
            roundWholeCrowns: true,
        );
        $stmt = $this->db->pdo()->prepare(
            'SELECT amount_minor FROM payroll_inputs WHERE supplier_id = ? AND external_id = ?',
        );
        $stmt->execute([$this->supplierId, 'attendance:' . self::PERIOD . ":{$this->jana['employment_id']}:PREMIE_PRIPLATKY"]);
        self::assertSame(123_500, (int) $stmt->fetchColumn());
    }

    /**
     * Srážky z podkladů = dohoda o srážce na importovaný měsíc. Opakování ji
     * nezdvojí, jiná částka ji opraví, dokud z ní schválená mzda nic nestrhla.
     */
    public function testDeductionsBecomeMonthlyDeductionAgreements(): void
    {
        if (!$this->db->hasTable('payroll_deduction_agreements')) {
            self::markTestSkipped('Chybí tabulka payroll_deduction_agreements.');
        }
        $files = AttendanceFixture::scenario();
        $rules = $this->rules($files);
        // Pravidlo navíc s jiným listem = jiný otisk, tedy nové použití téhož souboru.
        $apply = fn (string $marker): array => $this->service->apply(
            $this->supplierId,
            self::PERIOD,
            $files,
            [
                ['sheet' => 'Přehled', 'header' => 'Srážky', 'meaning' => 'net_other_deduction', 'unit' => 'amount', 'component_code' => null],
                ...$rules,
                ['sheet' => $marker, 'header' => 'nic', 'meaning' => 'ignore', 'unit' => null, 'component_code' => null],
            ],
            [],
            false,
            false,
            $this->userId,
            createDeductions: true,
        );

        $first = $apply('a');
        self::assertSame(['created' => 1, 'updated' => 0, 'unchanged' => 0, 'conflicts' => []], $first['deductions']);
        $reference = 'attendance:' . self::PERIOD . ":{$this->petr['employee_id']}:other";
        $agreement = $this->agreement($reference);
        // Obecná srážka z podkladů není doložená dohoda podle OZ, do JMHZ 10116 nepatří;
        // klíč reference zůstává `other`, aby opakovaný import našel starší dohody.
        self::assertSame('imported', $agreement['deduction_kind']);
        self::assertSame('active', $agreement['status']);
        self::assertSame(30000, (int) $agreement['requested_minor']);
        self::assertSame(30000, (int) $agreement['total_limit_minor']);
        self::assertSame('2026-06-01', (string) $agreement['valid_from']);
        self::assertSame('2026-06-30', (string) $agreement['valid_to']);

        self::assertSame(1, $apply('b')['deductions']['unchanged']);
        self::assertSame(1, $this->countRows(
            'SELECT COUNT(*) FROM payroll_deduction_agreements WHERE supplier_id = ?',
            [$this->supplierId],
        ));

        $this->db->pdo()->prepare('UPDATE payroll_deduction_agreements SET requested_minor = 100 WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        self::assertSame(1, $apply('c')['deductions']['updated']);
        self::assertSame(30000, (int) $this->agreement($reference)['requested_minor']);

        $this->db->pdo()->prepare(
            'UPDATE payroll_deduction_agreements SET requested_minor = 100, withheld_total_minor = 100 WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
        $blocked = $apply('d')['deductions'];
        self::assertSame(0, $blocked['updated']);
        self::assertCount(1, $blocked['conflicts']);
        self::assertStringContainsString('schválené mzdě', $blocked['conflicts'][0]['reason']);
    }

    public function testComparisonWithoutCalculatedRunListsReferencesAsMissing(): void
    {
        $files = AttendanceFixture::scenario();
        $result = $this->service->apply($this->supplierId, self::PERIOD, $files, $this->rules($files), [], false, false, $this->userId);

        $comparison = $this->service->comparison($this->supplierId, (int) $result['batch']['id']);
        self::assertNotNull($comparison);
        self::assertNull($comparison['run']);
        self::assertSame(['match' => 0, 'diff' => 0, 'missing' => 2], $comparison['summary']);
        $jana = array_column($comparison['rows'], null, 'employment_id')[$this->jana['employment_id']];
        self::assertSame(7_187_500, $jana['reference_gross_minor']);
        self::assertSame(5_512_050, $jana['reference_net_minor']);
        self::assertNull($jana['computed_gross_minor']);
        self::assertNull($this->service->comparison($this->supplierId, 999_999_999));
    }

    /**
     * Dotaz na výsledky běhu nad skutečnými daty testovací DB: firma s vypočtenou
     * revizí vrátí hrubou mzdu po vztazích a čistou po zaměstnancích.
     */
    public function testRunResultsQueryReadsCalculatedRevisions(): void
    {
        $row = $this->db->pdo()->query(
            'SELECT run.supplier_id, run.period_start
               FROM payroll_runs run
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
              WHERE revision.status IN ("calculated", "reviewed", "approved") AND run.status <> "cancelled"
              LIMIT 1',
        )?->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            self::markTestSkipped('Testovací DB nemá vypočtený mzdový běh.');
        }
        $results = $this->container
            ->get(\MyInvoice\Repository\Payroll\PayrollAttendanceImportRepository::class)
            ->runResultsForPeriod((int) $row['supplier_id'], (string) $row['period_start']);

        self::assertNotNull($results['run']);
        self::assertNotSame([], $results['gross']);
        foreach ($results['employee_of'] as $employmentId => $employeeId) {
            self::assertArrayHasKey($employmentId, $results['gross']);
            self::assertGreaterThanOrEqual(1, $results['employment_count'][$employeeId]);
        }
    }

    public function testSavedLinksMatchTheNextImport(): void
    {
        $files = AttendanceFixture::scenario();
        $this->service->apply($this->supplierId, self::PERIOD, $files, null, [], true, false, $this->userId);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ?', [$this->supplierId]));

        $persons = $this->byKey($this->service->preview($this->supplierId, self::PERIOD, $files, null, null)['persons']);
        // Rodné číslo v podkladech má přednost i před uloženou vazbou.
        self::assertSame('birth_number', $persons['jana testovaci']['match']['matched_by']);
        self::assertSame($this->jana['employment_id'], $persons['jana testovaci']['match']['employment_id']);
        self::assertSame('link', $persons['petr zkusebni']['match']['matched_by']);
    }

    public function testManualLinkOverridesMatchAndMustBeValidInPeriod(): void
    {
        $files = AttendanceFixture::scenario();
        $result = $this->service->apply(
            $this->supplierId,
            self::PERIOD,
            $files,
            null,
            [['person_key' => 'eva pokusna', 'employment_id' => $this->petr['employment_id']]],
            false,
            false,
            $this->userId,
        );
        $skipped = array_column($result['skipped_persons'], 'reason', 'key');
        self::assertArrayNotHasKey('eva pokusna', $skipped);
        self::assertStringContainsString('už je přiřazený', $skipped['petr zkusebni']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('neplatí');
        $this->service->apply(
            $this->supplierId,
            self::PERIOD,
            $files,
            null,
            [['person_key' => 'eva pokusna', 'employment_id' => 999_999_999]],
            false,
            false,
            $this->userId,
        );
    }

    public function testPersonsCreatesEmployeeActivatesAndLinks(): void
    {
        $result = $this->service->persons($this->supplierId, self::PERIOD, [
            [
                'person_key' => 'eva pokusna',
                'full_name' => 'Eva Pokusná',
                'first_name' => 'Eva',
                'last_name' => 'Pokusná',
                'birth_number' => null,
                'relation_type' => 'employment',
                'weekly_hours' => '20',
                'planned_start_on' => '2026-06-01',
                'personal_number' => 'Z003',
                'activate' => true,
            ],
            [
                'person_key' => 'bez jmena',
                'full_name' => '',
                'relation_type' => 'employment',
                'planned_start_on' => '2026-06-01',
                'activate' => false,
            ],
        ], $this->userId, null, null);

        self::assertSame('created', $result['results'][0]['status'], (string) $result['results'][0]['message']);
        self::assertSame('failed', $result['results'][1]['status']);
        self::assertNotEmpty($result['results'][1]['message']);
        $employmentId = (int) $result['results'][0]['employment_id'];
        $stmt = $this->db->pdo()->prepare(
            'SELECT status, actual_start_date FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$this->supplierId, $employmentId]);
        self::assertSame(['status' => 'active', 'actual_start_date' => '2026-06-01'], $stmt->fetch(PDO::FETCH_ASSOC));
        self::assertSame('Z003', $this->employmentCode($employmentId), 'Nová osoba má osobní číslo z podkladů.');
        self::assertSame(2, $this->countRows(
            'SELECT COUNT(*) FROM payroll_import_links WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        ));

        $persons = $this->byKey(
            $this->service->preview($this->supplierId, self::PERIOD, AttendanceFixture::scenario(), null, null)['persons'],
        );
        self::assertSame('linked', $persons['eva pokusna']['match']['status']);
        self::assertSame($employmentId, $persons['eva pokusna']['match']['employment_id']);
    }

    /**
     * Klient zná rodné číslo jen maskované; se soubory ho při zakládání
     * doplní server z podkladů podle klíče osoby.
     */
    public function testPersonsWithFilesTakeBirthNumberFromSourceData(): void
    {
        $result = $this->service->persons($this->supplierId, self::PERIOD, [
            [
                'person_key' => 'petr zkusebni',
                'full_name' => 'Petr Zkušební',
                'first_name' => 'Petr',
                'last_name' => 'Zkušební',
                'birth_number' => null,
                'relation_type' => 'employment',
                'weekly_hours' => '40',
                'planned_start_on' => '2026-06-01',
                'activate' => false,
            ],
        ], $this->userId, null, null, AttendanceFixture::scenario());

        self::assertSame('created', $result['results'][0]['status'], (string) $result['results'][0]['message']);
        $sensitive = $this->container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        $stmt = $this->db->pdo()->prepare(
            'SELECT value_masked FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ? AND identifier_type = "birth_number"',
        );
        $stmt->execute([$this->supplierId, (int) $result['results'][0]['employee_id']]);
        self::assertSame(
            [$sensitive->mask(AttendanceFixture::petrBirthNumber(), PayrollSensitiveField::PERSONAL_IDENTIFIER)],
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    /**
     * Datum narození a kód zdravotní pojišťovny klient nezná; při zakládání
     * je doplní server z podkladů stejně jako rodné číslo.
     */
    public function testPersonsWithFilesTakeBirthDateAndHealthInsurerFromSourceData(): void
    {
        $result = $this->service->persons($this->supplierId, self::PERIOD, [
            [
                'person_key' => 'petr zkusebni',
                'full_name' => 'Petr Zkušební',
                'first_name' => 'Petr',
                'last_name' => 'Zkušební',
                'birth_number' => null,
                'relation_type' => 'employment',
                'weekly_hours' => '40',
                'planned_start_on' => '2026-06-01',
                'activate' => false,
            ],
        ], $this->userId, null, null, AttendanceFixture::scenarioWithPersonalData());

        self::assertSame('created', $result['results'][0]['status'], (string) $result['results'][0]['message']);
        $employeeId = (int) $result['results'][0]['employee_id'];
        $stmt = $this->db->pdo()->prepare('SELECT birth_date FROM payroll_employees WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $employeeId]);
        self::assertSame('1985-03-12', $stmt->fetchColumn());
        $stmt = $this->db->pdo()->prepare(
            'SELECT insurer_code FROM payroll_person_health_coverage_history WHERE supplier_id = ? AND employee_id = ?',
        );
        $stmt->execute([$this->supplierId, $employeeId]);
        self::assertSame(['111'], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Úvazek z podkladů bývá text: číslo se z něj vezme a nepřečtený úvazek
     * osobu neshodí — vztah vznikne bez něj a výsledek řekne, co doplnit.
     */
    public function testPersonsReadWeeklyHoursFromTextAndSurviveUnreadableOne(): void
    {
        $base = ['relation_type' => 'employment', 'planned_start_on' => '2026-06-01', 'activate' => false, 'birth_number' => null];
        $result = $this->service->persons($this->supplierId, self::PERIOD, [
            $base + [
                'person_key' => 'nocni smena',
                'full_name' => 'Směna Noční',
                'first_name' => 'Směna',
                'last_name' => 'Noční',
                'weekly_hours' => 'noční - 18,75',
            ],
            $base + [
                'person_key' => 'bez uvazku',
                'full_name' => 'Úvazek Chybí',
                'first_name' => 'Úvazek',
                'last_name' => 'Chybí',
                'weekly_hours' => 'DPP',
            ],
        ], $this->userId, null, null);

        self::assertSame(
            ['created', 'created'],
            array_column($result['results'], 'status'),
            (string) json_encode($result['results'], JSON_UNESCAPED_UNICODE),
        );
        self::assertNull($result['results'][0]['message']);
        self::assertStringContainsString('Úvazek „DPP“', (string) $result['results'][1]['message']);
        $stmt = $this->db->pdo()->prepare(
            'SELECT weekly_hours FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?',
        );
        $stmt->execute([$this->supplierId, (int) $result['results'][0]['employment_id']]);
        self::assertEquals(18.75, (float) $stmt->fetchColumn());
    }

    /**
     * Hodinová sazba, sjednaný konec a číslo účtu jdou při založení stejnými
     * službami jako při ručním zadání: sazba do podmínek jako pravděpodobný
     * výdělek, konec do podmínek i vztahu (pozná ho hromadné ukončení dohod),
     * účet na kartu přes validaci karty. Neplatná hodnota osobu neshodí.
     */
    public function testPersonsTakeHourlyRateAgreedEndAndBankAccount(): void
    {
        $base = ['relation_type' => 'dpp', 'planned_start_on' => '2026-06-01', 'activate' => true, 'birth_number' => null];
        $result = $this->service->persons($this->supplierId, self::PERIOD, [
            $base + [
                'person_key' => 'alena sazbova',
                'full_name' => 'Alena Sazbová',
                'first_name' => 'Alena',
                'last_name' => 'Sazbová',
                'hourly_rate' => '180,50',
                'fixed_term_end_on' => '31. 8. 2026',
                'bank_account' => '1000000005/0100',
            ],
            $base + [
                'person_key' => 'bela chybna',
                'full_name' => 'Běla Chybná',
                'first_name' => 'Běla',
                'last_name' => 'Chybná',
                'hourly_rate' => 'dle dohody',
                'fixed_term_end_on' => '2026-05-31',
                'bank_account' => '123',
            ],
        ], $this->userId, null, null);

        self::assertSame(
            ['created', 'created'],
            array_column($result['results'], 'status'),
            (string) json_encode($result['results'], JSON_UNESCAPED_UNICODE),
        );
        self::assertNull($result['results'][0]['message']);
        $alena = (int) $result['results'][0]['employment_id'];
        self::assertSame(
            ['probable_hourly_earning_minor' => 18050, 'fixed_term_end_on' => '2026-08-31', 'end_date' => '2026-08-31'],
            $this->agreedTerms($alena),
        );
        $stmt = $this->db->pdo()->prepare(
            'SELECT probable_earning_rationale FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?',
        );
        $stmt->execute([$this->supplierId, $alena]);
        self::assertStringContainsString('180,50 Kč', (string) $stmt->fetchColumn());
        $sensitive = $this->container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        $stmt = $this->db->pdo()->prepare(
            'SELECT bank_account_masked, allocation_basis_points, effective_from, is_active,
                    verification_source, verified_by
               FROM payroll_person_accounts WHERE supplier_id = ? AND employee_id = ?',
        );
        $stmt->execute([$this->supplierId, (int) $result['results'][0]['employee_id']]);
        // Účet z podkladů rovnou ověří uživatel, který import spustil.
        self::assertSame([[
            'bank_account_masked' => $sensitive->mask('1000000005/0100', PayrollSensitiveField::BANK_ACCOUNT),
            'allocation_basis_points' => 10000,
            'effective_from' => '2026-06-01',
            'is_active' => 1,
            'verification_source' => 'user_verified',
            'verified_by' => $this->userId,
        ]], array_map(static fn (array $row): array => [
            'bank_account_masked' => (string) $row['bank_account_masked'],
            'allocation_basis_points' => (int) $row['allocation_basis_points'],
            'effective_from' => (string) $row['effective_from'],
            'is_active' => (int) $row['is_active'],
            'verification_source' => $row['verification_source'],
            'verified_by' => $row['verified_by'] === null ? null : (int) $row['verified_by'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));

        $closure = $this->container->get(PayrollEmploymentBulkClosure::class);
        self::assertInstanceOf(PayrollEmploymentBulkClosure::class, $closure);
        $expired = array_values(array_filter(
            $closure->preview($this->supplierId, 3, 12, '2026-09-15')['items'],
            static fn (array $item): bool => $item['employment_id'] === $alena,
        ));
        self::assertSame('expired', $expired[0]['group'] ?? null);
        self::assertSame('2026-08-31', $expired[0]['proposed_on']);

        $bela = (int) $result['results'][1]['employment_id'];
        $message = (string) $result['results'][1]['message'];
        self::assertStringContainsString('Hodinovou sazbu „dle dohody“', $message);
        self::assertStringContainsString('je před nástupem', $message);
        self::assertStringContainsString('Číslo účtu z podkladů neplatí', $message);
        self::assertSame(
            ['probable_hourly_earning_minor' => null, 'fixed_term_end_on' => null, 'end_date' => null],
            $this->agreedTerms($bela),
        );
        self::assertSame(0, $this->countRows(
            'SELECT COUNT(*) FROM payroll_person_accounts WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, (int) $result['results'][1]['employee_id']],
        ));
    }

    /** @return array{probable_hourly_earning_minor:?int,fixed_term_end_on:?string,end_date:?string} */
    private function agreedTerms(int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT terms.probable_hourly_earning_minor, terms.fixed_term_end_on, employment.end_date
               FROM payroll_employments employment
               JOIN payroll_employment_terms terms
                 ON terms.supplier_id = employment.supplier_id AND terms.employment_id = employment.id
              WHERE employment.supplier_id = ? AND employment.id = ?',
        );
        $stmt->execute([$this->supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return [
            'probable_hourly_earning_minor' => $row['probable_hourly_earning_minor'] === null ? null : (int) $row['probable_hourly_earning_minor'],
            'fixed_term_end_on' => $row['fixed_term_end_on'] === null ? null : (string) $row['fixed_term_end_on'],
            'end_date' => $row['end_date'] === null ? null : (string) $row['end_date'],
        ];
    }

    public function testProfilesCrudAndPreviewWithProfile(): void
    {
        $rules = [['sheet' => 'výpočet', 'header' => 'Dovolená', 'meaning' => 'vacation_hours', 'unit' => 'hours', 'component_code' => null]];
        $profile = $this->service->saveProfile($this->supplierId, null, 'Docházka výroba', $rules, $this->userId);
        self::assertSame('Docházka výroba', $profile['name']);
        self::assertSame($rules, $profile['rules']);

        $renamed = $this->service->saveProfile($this->supplierId, $profile['id'], 'Docházka sklad', $rules, $this->userId);
        self::assertSame($profile['id'], $renamed['id']);
        self::assertSame(['Docházka sklad'], array_column($this->service->profiles($this->supplierId), 'name'));

        $preview = $this->service->preview($this->supplierId, self::PERIOD, AttendanceFixture::scenario(), null, $profile['id']);
        $petr = $this->byKey($preview['persons'])['petr zkusebni'];
        self::assertSame('20.00', array_column($petr['metrics'], 'hours', 'meaning')['vacation_hours']);

        try {
            $this->service->saveProfile($this->supplierId, null, 'Docházka sklad', $rules, $this->userId);
            self::fail('Dva profily se stejným názvem nesmí vzniknout.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('už existuje', $e->getMessage());
        }

        self::assertTrue($this->service->deleteProfile($this->supplierId, $profile['id']));
        self::assertFalse($this->service->deleteProfile($this->supplierId, $profile['id']));
        self::assertSame([], $this->service->profiles($this->supplierId));
    }

    public function testSampleProfileCanBeRestoredAfterDeletion(): void
    {
        $sample = \MyInvoice\Service\Payroll\Import\Attendance\AttendanceSampleProfile::class;
        $first = $this->service->restoreSampleProfile($this->supplierId, $this->userId);
        self::assertTrue($first['restored']);
        self::assertSame($sample::NAME, $first['profile']['name']);
        self::assertTrue($first['profile']['is_sample']);
        self::assertSame($sample::VERSION, $first['profile']['sample_version']);
        self::assertSame($sample::rules(), $first['profile']['rules']);
        self::assertFalse($this->service->restoreSampleProfile($this->supplierId, $this->userId)['restored']);

        self::assertTrue($this->service->deleteProfile($this->supplierId, $first['profile']['id']));
        // Název vzoru mezitím obsadil vlastní profil: vzor se vrátí s příponou.
        $this->service->saveProfile($this->supplierId, null, $sample::NAME, [
            ['sheet' => 'výpočet', 'header' => 'Dovolená', 'meaning' => 'vacation_hours', 'unit' => 'hours', 'component_code' => null],
        ], $this->userId);
        $again = $this->service->restoreSampleProfile($this->supplierId, $this->userId);
        self::assertTrue($again['restored']);
        self::assertSame($sample::NAME . ' (2)', $again['profile']['name']);
        self::assertTrue($again['profile']['is_sample']);
    }

    public function testSourceChecksGuardPeriodAndInputsFromAnotherImport(): void
    {
        $files = AttendanceFixture::scenario();
        $rules = $this->rules($files);
        $clean = $this->service->preview($this->supplierId, self::PERIOD, $files, $rules, null);
        self::assertFalse($clean['source_checks']['requires_confirmation']);
        self::assertSame([], $clean['source_checks']['period']['detected']);

        // Podklady se názvem hlásí k jinému měsíci, než je vybraný.
        $named = array_map(static fn (array $file): array => ['name' => '05-2026 ' . $file['name']] + $file, $files);
        $preview = $this->service->preview($this->supplierId, self::PERIOD, $named, $rules, null);
        self::assertTrue($preview['source_checks']['period']['mismatch']);
        self::assertSame(['2026-05'], array_column($preview['source_checks']['period']['detected'], 'period'));
        try {
            $this->service->apply($this->supplierId, self::PERIOD, $named, $rules, [], false, true, $this->userId, sourceChecksConfirmed: false);
            self::fail('Podklady jiného měsíce se bez potvrzení nesmí použít.');
        } catch (AttendanceSourceConfirmationRequired $e) {
            self::assertTrue($e->checks['period']['mismatch']);
            self::assertStringContainsString('05/2026', $e->getMessage());
        }
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM payroll_attendance_imports WHERE supplier_id = ?', [$this->supplierId]));

        // Bez kontroly (převod, skripty) i s potvrzením se dávka použije.
        $this->service->apply($this->supplierId, self::PERIOD, $files, $rules, [], false, true, $this->userId);
        $same = $this->service->preview($this->supplierId, self::PERIOD, $files, $rules, null);
        self::assertSame([], $same['source_checks']['other_sources'], 'Opakované použití týchž souborů není jiný zdroj.');

        $corrected = AttendanceFixture::scenario(2500);
        $other = $this->service->preview($this->supplierId, self::PERIOD, $corrected, $this->rules($corrected), null);
        self::assertTrue($other['source_checks']['requires_confirmation']);
        self::assertCount(1, $other['source_checks']['other_sources']);
        self::assertSame(3, $other['source_checks']['other_sources'][0]['active_inputs']);
        try {
            $this->service->apply($this->supplierId, self::PERIOD, $corrected, $this->rules($corrected), [], false, true, $this->userId, sourceChecksConfirmed: false);
            self::fail('Druhý import téhož období se bez potvrzení nesmí použít.');
        } catch (AttendanceSourceConfirmationRequired $e) {
            self::assertStringContainsString('dvojí vstupy', $e->getMessage());
        }
        $confirmed = $this->service->apply($this->supplierId, self::PERIOD, $corrected, $this->rules($corrected), [], false, true, $this->userId, sourceChecksConfirmed: true);
        self::assertFalse($confirmed['replayed']);
    }

    public function testAutomaticProfileSkipsProfileThatKnowsNoColumn(): void
    {
        // Profil jiného systému (převod mezd z POHODY) pravidly míří na vlastní list.
        $this->service->saveProfile($this->supplierId, null, 'POHODA mzdy (převod)', [
            ['sheet' => 'mzdy-pohoda', 'header' => 'Zaměstnanec', 'meaning' => 'person_name', 'unit' => 'text', 'component_code' => null],
            ['sheet' => 'mzdy-pohoda', 'header' => 'Odpracováno (h)', 'meaning' => 'worked_hours', 'unit' => 'hours', 'component_code' => null],
        ], $this->userId);
        $preview = $this->service->preview($this->supplierId, self::PERIOD, AttendanceFixture::scenario(), null, null);
        self::assertNull($preview['profile']['id'], 'Profil, který v souborech nezná žádný sloupec, se nesmí vybrat automaticky.');
        self::assertTrue($preview['profile']['auto']);

        $own = $this->service->saveProfile($this->supplierId, null, 'Docházka sklad', $this->rules(AttendanceFixture::scenario()), $this->userId);
        $again = $this->service->preview($this->supplierId, self::PERIOD, AttendanceFixture::scenario(), null, null);
        self::assertSame($own['id'], $again['profile']['id']);
        self::assertTrue($again['profile']['auto']);
    }

    public function testActionDecodesFilesAndAnswersWithoutRawBirthNumber(): void
    {
        $action = $this->container->get(PayrollAttendanceImportAction::class);
        self::assertInstanceOf(PayrollAttendanceImportAction::class, $action);
        $files = array_map(
            static fn (array $file): array => ['name' => $file['name'], 'content_base64' => base64_encode($file['content'])],
            AttendanceFixture::scenario(),
        );

        $preview = $action->preview(
            $this->request('POST', '/api/payroll/imports/attendance/preview')
                ->withParsedBody(['period' => self::PERIOD, 'files' => $files, 'rules' => null, 'profile_id' => null]),
            new Response(),
        );
        self::assertSame(200, $preview->getStatusCode(), $this->body($preview));
        self::assertStringNotContainsString(AttendanceFixture::janaBirthNumber(), $this->body($preview));
        self::assertCount(3, $this->json($preview)['persons']);

        $apply = $action->apply(
            $this->request('POST', '/api/payroll/imports/attendance/apply')->withParsedBody([
                'period' => self::PERIOD,
                'files' => $files,
                'rules' => $this->json($preview)['rules'],
                'links' => [],
                'save_links' => false,
                'create_inputs' => true,
            ]),
            new Response(),
        );
        self::assertSame(201, $apply->getStatusCode(), $this->body($apply));
        $batchId = (int) $this->json($apply)['batch']['id'];

        $detail = $action->batch($this->request('GET', "/api/payroll/imports/attendance/batches/{$batchId}"), new Response(), ['id' => (string) $batchId]);
        self::assertSame(200, $detail->getStatusCode(), $this->body($detail));
        self::assertSame($batchId, $this->json($detail)['batch']['id']);

        $missing = $action->preview(
            $this->request('POST', '/api/payroll/imports/attendance/preview')->withParsedBody(['period' => self::PERIOD, 'files' => []]),
            new Response(),
        );
        self::assertSame(422, $missing->getStatusCode());

        $token = $action->preview(
            $this->request('POST', '/api/payroll/imports/attendance/preview')
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'token')
                ->withParsedBody(['period' => self::PERIOD, 'files' => $files]),
            new Response(),
        );
        self::assertNotSame(200, $token->getStatusCode(), 'Import s osobními údaji je jen pro přihlášenou session.');
    }

    /**
     * Osobní číslo z podkladů se převezme jen tam, kde vztah nese automatický
     * kód; ručně zadané číslo zůstává a rozdíl se ohlásí.
     */
    public function testPersonalNumbersAreAdoptedOnlyOverGeneratedCodes(): void
    {
        $this->db->pdo()->prepare('UPDATE payroll_employments SET code = "ZAM-7" WHERE supplier_id = ? AND id = ?')
            ->execute([$this->supplierId, $this->jana['employment_id']]);

        $result = $this->service->apply(
            $this->supplierId,
            self::PERIOD,
            AttendanceFixture::scenario(),
            null,
            [],
            false,
            false,
            $this->userId,
            adoptPersonalNumbers: true,
        );

        self::assertSame(1, $result['personal_numbers_adopted']);
        $conflicts = array_column($result['personal_number_conflicts'], 'reason', 'key');
        self::assertSame(['petr zkusebni'], array_keys($conflicts));
        self::assertStringContainsString('ZAM-P', $conflicts['petr zkusebni']);
        self::assertSame('Z001', $this->employmentCode($this->jana['employment_id']));
        self::assertSame('ZAM-P', $this->employmentCode($this->petr['employment_id']));

        $persons = $this->byKey($this->service->preview($this->supplierId, self::PERIOD, AttendanceFixture::scenario(), null, null)['persons']);
        self::assertSame('Z001', $persons['jana testovaci']['match']['employment_code']);
    }

    private function employmentCode(int $employmentId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT code FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $employmentId]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * Složku, kterou profil definuje a firma nemá, import založí jen na
     * potvrzení — pak částky projdou vstupní branou bez chyby.
     */
    public function testMissingComponentDefinedByProfileIsCreatedOnConfirmation(): void
    {
        $files = AttendanceFixture::scenario();
        $rules = $this->rules($files);
        $components = [['code' => self::MISSING_COMPONENT, 'name' => 'Syntetický úkol', 'kind' => 'task_wage']];

        $preview = $this->service->preview($this->supplierId, self::PERIOD, $files, $rules, null, $components);
        $check = array_column($preview['component_checks'], null, 'component_code')[self::MISSING_COMPONENT];
        self::assertSame('will_create', $check['status']);
        self::assertSame('Syntetický úkol', $check['name']);

        $result = $this->service->apply(
            $this->supplierId,
            self::PERIOD,
            $files,
            $rules,
            [],
            false,
            true,
            $this->userId,
            $components,
            true,
        );
        self::assertSame([self::MISSING_COMPONENT], $result['components_created']);
        self::assertSame([], $result['inputs']['errors']);
        self::assertSame(5, $result['inputs']['created']);
        self::assertSame(1, $this->countRows(
            'SELECT COUNT(*) FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ? AND frequency_kind = "one_off" AND component_kind = "task_wage"',
            [$this->supplierId, self::MISSING_COMPONENT],
        ));
    }

    public function testProfileExportImportAndCopyToAnotherSupplier(): void
    {
        $rules = [['sheet' => 'výpočet*', 'header' => '*', 'meaning' => 'component', 'unit' => 'amount', 'component_code' => '*']];
        $components = [['code' => 'PRIPLATEK_TEST', 'name' => 'Syntetický příplatek', 'kind' => 'premium']];
        $profile = $this->service->saveProfile($this->supplierId, null, 'Sdílený profil', $rules, $this->userId, $components);

        $export = $this->service->exportProfile($this->supplierId, $profile['id']);
        self::assertSame(AttendanceImportService::EXPORT_FORMAT, $export['format']);
        $imported = $this->service->importProfile($this->supplierId, $export, null, $this->userId);
        self::assertSame('Sdílený profil (2)', $imported['name']);
        self::assertSame($profile['rules'], $imported['rules']);
        self::assertSame($components, $imported['components']);
        self::assertFalse($imported['is_sample']);

        $other = $this->createIsolatedSupplier($this->db->pdo(), $this->supplierId);
        $copy = $this->service->copyProfile($this->supplierId, $profile['id'], $other, $this->userId);
        self::assertSame('Sdílený profil', $copy['name']);
        self::assertSame($components, $copy['components']);
        self::assertSame(1, $this->countRows(
            'SELECT COUNT(*) FROM payroll_import_profiles WHERE supplier_id = ?',
            [$other],
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->service->importProfile($this->supplierId, ['format' => 'jiny', 'version' => 1], null, $this->userId);
    }

    /**
     * Pravidla z náhledu s úkolovou mzdou přemapovanou na složku, která ve
     * firmě neexistuje — test tak ověří, že chybějící složka skončí chybou.
     *
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @return list<array<string,mixed>>
     */
    private function rules(array $files): array
    {
        $rules = $this->service->preview($this->supplierId, self::PERIOD, $files, null, null)['rules'];
        foreach ($rules as &$rule) {
            if (($rule['component_code'] ?? null) === 'MZDA_UKOLOVA') {
                $rule['component_code'] = self::MISSING_COMPONENT;
            }
        }
        unset($rule);

        return $rules;
    }

    /** @return array{employee_id:int,employment_id:int} */
    private function createEmployment(string $name, string $code): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)',
        )->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", "2026-01-01", 4200000, 0, 1)',
        )->execute([$this->supplierId, $employeeId, $code]);

        return ['employee_id' => $employeeId, 'employment_id' => (int) $pdo->lastInsertId()];
    }

    private function insertBirthNumber(int $employeeId, string $value): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_person_identifiers
                (supplier_id, employee_id, identifier_type, value_ciphertext, value_hash, value_masked)
             VALUES (?, ?, "birth_number", "enc:v2:pending", ?, "")',
        )->execute([$this->supplierId, $employeeId, random_bytes(32)]);
        $id = (int) $pdo->lastInsertId();
        $sensitive = $this->container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        $sealed = $sensitive->seal($value, PayrollSensitiveField::PERSONAL_IDENTIFIER, $this->supplierId, $id);
        $pdo->prepare(
            'UPDATE payroll_person_identifiers
                SET value_ciphertext = ?, value_hash = ?, value_masked = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([$sealed->ciphertext, $sealed->lookupHash, $sealed->masked, $this->supplierId, $id]);
    }

    private function createComponent(string $code): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment,
                 jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code,
                 valid_from, is_active)
             VALUES (?, ?, ?, "bonus", "monetary", "one_off", "included",
                     "included", "included", "included", "included",
                     "included", "included", "included", "included",
                     "521", "331", "2026-01-01", 1)',
        )->execute([$this->supplierId, $code, "Syntetická {$code}"]);
    }

    private function bonus(int $employmentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT amount_minor FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND external_id = ?',
        );
        $stmt->execute([$this->supplierId, $employmentId, 'attendance:' . self::PERIOD . ":{$employmentId}:ODMENA"]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(1, $rows, 'Odměna z docházky má existovat právě jednou.');

        return (int) $rows[0];
    }

    /** @return array<string,mixed> */
    private function agreement(string $reference): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_deduction_agreements WHERE supplier_id = ? AND agreement_reference = ?',
        );
        $stmt->execute([$this->supplierId, $reference]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Dohoda {$reference} neexistuje.");

        return $row;
    }

    /** @param list<mixed> $params */
    private function countRows(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<array<string,mixed>> $persons
     * @return array<string,array<string,mixed>>
     */
    private function byKey(array $persons): array
    {
        return array_column($persons, null, 'key');
    }

    private function request(string $method, string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    private function body(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode($this->body($response), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
