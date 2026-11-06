<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Registration;

use MyInvoice\Action\Payroll\PayrollRegistrationImportAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollAverageEarningRepository;
use MyInvoice\Repository\Payroll\PayrollDependantRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportLookup;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportService;
use MyInvoice\Service\Payroll\PayrollDependantValidator;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\JmhzReportFixtures;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\RegistrationXmlFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Import měsíčních hlášení JMHZ cizího mzdového programu nad izolovanou
 * syntetickou firmou. Firma „přešla" na MyÚčto od dubna 2026, takže leden až
 * březen jsou měsíce předchozího programu.
 */
#[Group('integration')]
final class JmhzReportImportServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const MODULE_START = '2026-04-01';

    private Connection $db;
    private ContainerInterface $container;
    private RegistrationImportService $imports;
    private int $supplierId;
    private int $userId;
    private string $oic;
    private string $idPpv = '200000000000000000101';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 6) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $this->container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $this->container->get(Connection::class);
        $this->imports = $this->container->get(RegistrationImportService::class);
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
            'jmhz-import-' . bin2hex(random_bytes(4)) . '@invalid.example',
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
     * Kruh: syntetický zdroj → skutečný serializér → import → evidence.
     * Převzaté hodnoty musí odpovídat zdroji a opakovaný import nesmí nic
     * přidat.
     */
    public function testForeignReportsRoundTripIntoEvidence(): void
    {
        [$employeeId, $employmentId] = $this->registerEmployee(withIdentifiers: true);
        $files = [];
        foreach ([1, 2, 3] as $month) {
            $files[] = $this->file("jmhz-{$month}.xml", JmhzReportFixtures::report([JmhzReportFixtures::person([
                'oic' => $this->oic,
                'id_ppv' => $this->idPpv,
            ])], 2026, $month));
        }

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        self::assertSame(['JMHZ', 'JMHZ', 'JMHZ'], array_column($preview['files'], 'document_type'));
        self::assertSame(['2026-01', '2026-02', '2026-03'], array_column($preview['files'], 'period'));
        self::assertCount(3, $preview['records']);
        $january = $preview['records'][0];
        self::assertSame('JMHZ', $january['document_type'], $this->dump($january));
        self::assertSame('2026-01', $january['period']);
        self::assertSame('matched', $january['match']['status']);
        self::assertSame('id_ppv', $january['match']['matched_by']);
        self::assertSame($employmentId, $january['match']['employment_id']);
        self::assertSame('update', $january['operation'], $this->dump($january));
        self::assertTrue($january['selectable']);
        self::assertNull($january['blocker']);
        $fields = array_column($january['changes'], 'imported', 'field');
        self::assertSame('Brno', $fields['work_place']);
        self::assertSame('582786', $fields['jmhz_workplace_municipality_code']);
        self::assertSame('signed', $fields['tax_declaration']);
        self::assertSame('verified', $fields['tax_credit:taxpayer']);
        self::assertSame('not_claimed', $fields['social_discount']);
        self::assertArrayHasKey('dependant:0', $fields);
        self::assertSame([
            'period' => '2026-01',
            'gross_minor' => 4_000_000,
            'tax_base_minor' => 4_000_000,
            'advance_tax_minor' => 216_300,
            'bonus_minor' => null,
            'social_base_minor' => 4_000_000,
            'worked_hours' => '168.000',
            'average_hourly_minor' => 23_810,
        ], $january['history']);
        self::assertSame(3, $preview['summary']['update']);
        self::assertContains($employmentId, array_column($preview['employment_options'], 'employment_id'));

        self::assertCount(1, $preview['opening_balances']);
        $opening = $preview['opening_balances'][0];
        self::assertSame('ready', $opening['status'], (string) $opening['reason']);
        self::assertSame($employeeId, $opening['employee_id']);
        self::assertSame(2026, $opening['year']);
        self::assertSame([1, 2, 3], array_column($opening['months'], 'month'));
        self::assertCount(1, $preview['averages']);
        $average = $preview['averages'][0];
        self::assertSame('ready', $average['status'], (string) $average['reason']);
        self::assertSame([2026, 2], [$average['year'], $average['quarter']]);
        self::assertSame(12_000_000, $average['gross_minor']);
        self::assertSame(30_240, $average['worked_minutes']);
        self::assertSame(23_810, $average['average_hourly_minor']);

        $applied = $this->apply($files, array_column($preview['records'], 'key'), openings: true, averages: true);
        $january = $applied['results'][0];
        self::assertSame('applied', $january['status'], (string) $january['message']);
        self::assertNull($january['message'], (string) $january['message']);
        foreach (['terms', 'tax_declaration', 'tax_credit_claims', 'social_discount', 'dependants'] as $operation) {
            self::assertContains($operation, $january['operations']);
        }
        self::assertSame(['saved' => 1, 'skipped' => []], $applied['opening_balances']);
        self::assertSame(['created' => 1, 'approved' => 0, 'skipped' => []], $applied['averages']);
        self::assertSame(['completed' => 0, 'failed' => []], $applied['change_checklist']);

        $reference = 'jmhz-import:' . hash('sha256', base64_decode($files[0]['content_base64'], true))
            . ':' . JmhzReportFixtures::guid(1, 101);
        $terms = $this->container->get(JmhzReportLookup::class)->termVersions($this->supplierId, $employmentId);
        self::assertCount(1, $terms, 'Leden opravuje verzi od nástupu, další měsíce nic nemění.');
        self::assertSame(
            ['Brno', '582786', 'CZ', 'no', 'no', 'no'],
            [
                $terms[0]['work_place'],
                $terms[0]['jmhz_workplace_municipality_code'],
                $terms[0]['jmhz_workplace_country_code'],
                $terms[0]['jmhz_apz_contribution_status'],
                $terms[0]['jmhz_functional_benefits_status'],
                $terms[0]['jmhz_temporary_assignment_status'],
            ],
        );

        $sections = $this->container->get(PayrollPersonStatutoryEvidenceRepository::class)
            ->editorView($this->supplierId, $employeeId, '2026-03-01')['sections'];
        self::assertSame([['signed', '2026-01-01', null, $reference]], $this->rows($sections['tax_declarations'], ['status']));
        self::assertSame([['taxpayer', '2026-01-01', null, $reference]], $this->rows($sections['tax_credit_claims'], ['credit_kind']));
        self::assertSame([['not_claimed', '2026-01-01', null, null]], $this->rows($sections['social_discount_claims'], ['status']));

        $dependants = $this->container->get(PayrollDependantRepository::class)
            ->overview($this->supplierId, $employeeId, '2026-03-01')['dependants'];
        self::assertCount(1, $dependants);
        self::assertSame(['Eliška', 'Testovací', '2018-05-20', 'child_own'], [
            $dependants[0]['given_name'],
            $dependants[0]['family_name'],
            $dependants[0]['birth_date'],
            $dependants[0]['relation'],
        ]);
        self::assertCount(1, $dependants[0]['claims']);
        $claim = $dependants[0]['claims'][0];
        self::assertSame([1, 'claimed', 'verified', '2026-01-01', null, 'none', $reference], [
            $claim['child_order'],
            $claim['credit_status'],
            $claim['evidence_status'],
            $claim['effective_from'],
            $claim['effective_to'],
            $claim['other_household_caregiver_status'],
            $claim['evidence_reference'],
        ]);

        $openings = $this->container->get(PayrollOpeningBalanceService::class)->current($this->supplierId, $employeeId, 2026);
        $expectedMonth = static fn (int $month): array => [
            'month' => $month,
            'social_assessment_base_minor_units' => 4_000_000,
            'advance_base_minor_units' => 4_000_000,
            'advance_tax_minor_units' => 216_300,
            'withholding_base_minor_units' => 0,
            'withholding_tax_minor_units' => 0,
            'applied_non_refundable_credits_minor_units' => 257_000,
            'applied_child_credit_minor_units' => 126_700,
            'tax_bonus_minor_units' => 0,
            'bonus_qualifying_income_minor_units' => 4_000_000,
        ];
        self::assertEquals([$expectedMonth(1), $expectedMonth(2), $expectedMonth(3)], $openings['months']);

        $averages = $this->container->get(PayrollAverageEarningRepository::class)->list($this->supplierId, $employmentId);
        self::assertCount(1, $averages);
        self::assertSame(
            [2026, 2, 12_000_000, 30_240, 63, 23_810, 'manual_review'],
            [
                (int) $averages[0]['applicable_year'],
                (int) $averages[0]['applicable_quarter'],
                (int) $averages[0]['gross_earnings_minor'],
                (int) $averages[0]['worked_minutes'],
                (int) $averages[0]['worked_days'],
                (int) $averages[0]['average_hourly_minor'],
                (string) $averages[0]['status'],
            ],
        );

        $again = $this->imports->preview($this->supplierId, 'test', $files);
        self::assertSame(['none', 'none', 'none'], array_column($again['records'], 'operation'), $this->dump($again['records']));
        self::assertSame('unchanged', $again['opening_balances'][0]['status']);
        self::assertSame('exists', $again['averages'][0]['status']);
        $second = $this->apply($files, array_column($again['records'], 'key'), openings: true, averages: true);
        self::assertSame(['skipped', 'skipped', 'skipped'], array_column($second['results'], 'status'));
        self::assertSame(0, $second['opening_balances']['saved']);
        self::assertSame(0, $second['averages']['created']);
        self::assertSame(1, $this->tableRows('payroll_dependants'));
        self::assertSame(1, $this->tableRows('payroll_person_tax_child_claims'));
        self::assertSame(1, $this->tableRows('payroll_person_tax_declarations'));
    }

    /**
     * Volba automatického schválení: povinnosti ke změně, které založila
     * nová verze podmínek z importu, se odškrtnou a průměr se rovnou schválí.
     * Povinnosti při nástupu (z dřívější registrace) zůstanou účetní.
     */
    public function testAutoApprovalCompletesImportedChangeDutiesAndApprovesAverages(): void
    {
        [, $employmentId] = $this->registerEmployee(withIdentifiers: true);
        $files = [];
        foreach ([1, 2, 3] as $month) {
            $files[] = $this->file("jmhz-{$month}.xml", JmhzReportFixtures::report([JmhzReportFixtures::person([
                'oic' => $this->oic,
                'id_ppv' => $this->idPpv,
                'work_place' => $month === 1 ? 'Brno' : 'Olomouc',
                'municipality' => $month === 1 ? '582786' : '500496',
            ])], 2026, $month));
        }
        $keys = array_column($this->imports->preview($this->supplierId, 'test', $files)['records'], 'key');
        $onboardingBefore = $this->checklistStatuses($employmentId)['onboarding'] ?? [];

        $applied = $this->apply($files, $keys, averages: true, autoChanges: true, autoAverages: true);

        self::assertContains('applied', array_column($applied['results'], 'status'), $this->dump($applied['results']));
        $terms = $this->container->get(JmhzReportLookup::class)->termVersions($this->supplierId, $employmentId);
        self::assertCount(2, $terms, 'Únorová změna pracoviště musí založit novou verzi podmínek.');
        // Změna z převzatého měsíce vyřídil předchozí program (PayrollPredecessorObligationScope):
        // povinnost ke změně se nezakládá, takže nemá co zůstat nesplněné ani co odškrtávat.
        $statuses = $this->checklistStatuses($employmentId);
        self::assertArrayNotHasKey('pending', $statuses['change'] ?? [], $this->dump($statuses));
        self::assertSame(
            ['completed' => $statuses['change']['completed'] ?? 0, 'failed' => []],
            $applied['change_checklist'],
        );
        self::assertSame($onboardingBefore, $statuses['onboarding'] ?? [], 'Nástupní povinnosti import nemění.');

        self::assertSame(1, $applied['averages']['approved'], $this->dump($applied['averages']));
        $averages = $this->container->get(PayrollAverageEarningRepository::class)->list($this->supplierId, $employmentId);
        self::assertSame('approved', (string) $averages[0]['status']);
    }

    /**
     * Začátek vedení mezd v lednu, ale leden až březen doložil předchozí
     * program a běh za ně v MyÚčtu není. Dřív počáteční stavy z náhledu tiše
     * zmizely a běh pak zapsal nuly; teď se ukážou zablokované s návodem.
     */
    public function testOpeningBalancesExplainModuleStartContradiction(): void
    {
        $this->db->pdo()->prepare('UPDATE payroll_module_state SET start_period = ? WHERE supplier_id = ?')
            ->execute(['2026-01-01', $this->supplierId]);
        $this->registerEmployee(withIdentifiers: true);
        $files = [];
        foreach ([1, 2, 3] as $month) {
            $files[] = $this->file("jmhz-{$month}.xml", JmhzReportFixtures::report([JmhzReportFixtures::person([
                'oic' => $this->oic,
                'id_ppv' => $this->idPpv,
            ])], 2026, $month));
        }

        $openings = $this->imports->preview($this->supplierId, 'test', $files)['opening_balances'];

        self::assertCount(1, $openings, $this->dump($openings));
        self::assertSame('blocked', $openings[0]['status']);
        self::assertStringContainsString('Začátek vedení mezd v MyÚčtu je nastavený na 2026-01', (string) $openings[0]['reason']);
    }

    /**
     * IMP-12 (W2): druh činnosti z hlášení (zde první pozice kódu ELDP) se
     * s evidencí jen ověří. Rozdíl je varování, shoda mlčí.
     */
    public function testActivityCodeDifferingFromEvidenceIsAWarning(): void
    {
        $this->registerEmployee(withIdentifiers: true);
        $xml = JmhzReportFixtures::report([JmhzReportFixtures::person([
            'oic' => $this->oic,
            'id_ppv' => $this->idPpv,
        ])], 2026, 2);

        $same = $this->imports->preview($this->supplierId, 'test', [$this->file('same.xml', $xml)])['records'][0];
        self::assertStringNotContainsString('Druh činnosti ve vztahu', implode("\n", $same['warnings']));

        $changed = str_replace('<form:kod>1++</form:kod>', '<form:kod>2++</form:kod>', $xml);
        self::assertNotSame($xml, $changed);
        $record = $this->imports->preview($this->supplierId, 'test', [$this->file('changed.xml', $changed)])['records'][0];

        self::assertStringContainsString('Druh činnosti ve vztahu', implode("\n", $record['warnings']), $this->dump($record));
        self::assertStringContainsString('(2)', implode("\n", $record['warnings']));
    }

    /**
     * Hlášení vězně (formulář `vezen`, datový scénář 4) import převezme,
     * neodmítne ani nezahodí. Vztah evidovaný bez bližšího určení „výkon
     * trestu" by ale další hlášení podal formulářem bez příznaku, a na to
     * import upozorní.
     */
    public function testPrisonerFormIsImportedAndDifferingRelationshipDetailWarns(): void
    {
        $this->registerEmployee(withIdentifiers: true);
        $xml = JmhzReportFixtures::report([JmhzReportFixtures::person([
            'oic' => $this->oic,
            'id_ppv' => $this->idPpv,
            'selector' => ['scenario_key' => 'scenario_4', 'activity_code' => '1', 'relationship_detail_code' => '2'],
        ])], 2026, 2);
        self::assertStringContainsString('<form:vezen', $xml);

        $record = $this->imports->preview($this->supplierId, 'test', [$this->file('vezen.xml', $xml)])['records'][0];

        self::assertNull($record['blocker'], $this->dump($record));
        self::assertStringContainsString('formulářem vezen', implode("\n", $record['warnings']), $this->dump($record));
        self::assertStringContainsString('formulář bezPriznaku', implode("\n", $record['warnings']));

        $ordinary = JmhzReportFixtures::report([JmhzReportFixtures::person([
            'oic' => $this->oic,
            'id_ppv' => $this->idPpv,
        ])], 2026, 2);
        $same = $this->imports->preview($this->supplierId, 'test', [$this->file('same.xml', $ordinary)])['records'][0];
        self::assertStringNotContainsString('formulářem', implode("\n", $same['warnings']));
    }

    /**
     * PRE-03 (W2): předchozí program vedl slevu zaměstnavatele (10372), ale
     * v evidenci není přijatý záměr OZUSPOJ. Převzetí na to upozorní; se
     * záměrem v evidenci mlčí.
     */
    public function testEmployerDiscountWithoutIntentInEvidenceWarns(): void
    {
        [$employeeId, $employmentId] = $this->registerEmployee(withIdentifiers: true);
        $xml = str_replace(
            '<form:slevaZamestnavateleEvidovana>false</form:slevaZamestnavateleEvidovana>',
            '<form:slevaZamestnavateleEvidovana>true</form:slevaZamestnavateleEvidovana>',
            JmhzReportFixtures::report([JmhzReportFixtures::person([
                'oic' => $this->oic,
                'id_ppv' => $this->idPpv,
            ])], 2026, 2),
        );
        $files = [$this->file('discount.xml', $xml)];

        $record = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertStringContainsString('záměr slevy (OZUSPOJ)', implode("\n", $record['warnings']), $this->dump($record));

        $repository = $this->container->get(\MyInvoice\Repository\Payroll\PayrollDiscountIntentRepository::class);
        $repository->insertPredecessorAccepted(
            $this->supplierId,
            'test',
            $employeeId,
            $employmentId,
            'age_55_plus',
            '2026-01-01',
            115,
            '2026-01-05',
            'ozuspoj_xml',
            str_repeat('a', 64),
            $this->userId,
        );

        $covered = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertStringNotContainsString('záměr slevy (OZUSPOJ)', implode("\n", $covered['warnings']));
    }

    public function testUnpairedFormIsAssignedManually(): void
    {
        [$employeeId, $employmentId] = $this->registerEmployee(withIdentifiers: false);
        $files = [$this->file('jmhz-2.xml', JmhzReportFixtures::report([JmhzReportFixtures::person([
            'oic' => $this->oic,
            'id_ppv' => $this->idPpv,
        ])], 2026, 2))];

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        $record = $preview['records'][0];
        self::assertSame('not_found', $record['match']['status'], $this->dump($record));
        self::assertSame('pair_required', $record['operation']);
        self::assertTrue($record['selectable']);
        self::assertSame(1, $preview['summary']['pair_required']);
        self::assertSame($this->oic, array_column($record['changes'], 'imported', 'field')['person_external_identifier']);
        self::assertSame([$employmentId], array_column($preview['employment_options'], 'employment_id'));

        $unpaired = $this->apply($files, [$record['key']]);
        self::assertSame('skipped', $unpaired['results'][0]['status']);
        self::assertStringContainsString('není spárovaný', (string) $unpaired['results'][0]['message']);

        $paired = $this->apply($files, [$record['key']], pairs: [['key' => $record['key'], 'employment_id' => $employmentId]]);
        $result = $paired['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertContains('identifiers', $result['operations']);
        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        self::assertTrue($identities->activePersonExternalIdMatches($this->supplierId, $employeeId, 'test', $this->oic));
        self::assertTrue($identities->activeEmploymentExternalIdMatches($this->supplierId, $employmentId, 'test', $this->idPpv));
        self::assertSame(
            'verified_manual_import',
            $this->container->get(PayrollRegistrationIdentityRepository::class)
                ->activeExternalId($this->supplierId, $employmentId, 'test', 'id_ppv')['source_kind'],
        );

        $again = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertSame('id_ppv', $again['match']['matched_by']);
        self::assertSame('none', $again['operation'], $this->dump($again));
    }

    public function testManualPairConflictingWithAutomaticMatchIsBlocked(): void
    {
        [, $employmentId] = $this->registerEmployee(withIdentifiers: true);
        $files = [$this->file('jmhz-2.xml', JmhzReportFixtures::report([JmhzReportFixtures::person([
            'oic' => $this->oic,
            'id_ppv' => $this->idPpv,
        ])], 2026, 2))];
        $key = $this->imports->preview($this->supplierId, 'test', $files)['records'][0]['key'];

        $result = $this->apply($files, [$key], pairs: [['key' => $key, 'employment_id' => $employmentId + 1000]])['results'][0];

        self::assertSame('skipped', $result['status']);
        self::assertStringContainsString('neexistuje', (string) $result['message']);
    }

    /** Opravné podání (O, týž GUID formuláře) nahrazuje řádné téhož měsíce. */
    public function testCorrectionReplacesTheRegularFormOfTheSameMonth(): void
    {
        [, $employmentId] = $this->registerEmployee(withIdentifiers: true);
        $person = ['oic' => $this->oic, 'id_ppv' => $this->idPpv];
        $files = [
            $this->file('radne.xml', JmhzReportFixtures::report([JmhzReportFixtures::person($person)], 2026, 1)),
            $this->file('opravne.xml', JmhzReportFixtures::report(
                [JmhzReportFixtures::person($person + ['work_place' => 'Ostrava', 'municipality' => '554821'])],
                2026,
                1,
                ['type' => 'O', 'filled_at' => '2026-02-20T08:00:00Z'],
            )),
        ];

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        [$regular, $correction] = $preview['records'];
        self::assertSame('none', $regular['operation']);
        self::assertStringContainsString('nahradilo', implode(' ', $regular['warnings']));
        self::assertSame('Měsíční hlášení 2026-01 – opravný formulář', $correction['action_label']);
        self::assertSame('Ostrava', array_column($correction['changes'], 'imported', 'field')['work_place']);

        $applied = $this->apply($files, [$regular['key'], $correction['key']]);
        self::assertSame(['skipped', 'applied'], array_column($applied['results'], 'status'));
        $terms = $this->container->get(JmhzReportLookup::class)->termVersions($this->supplierId, $employmentId);
        self::assertSame('Ostrava', $terms[0]['work_place']);
    }

    /**
     * Nahraná hlášení jdou do téže historie podání předchozím programem jako hlášení
     * z PAMICA (zdroj `jmhz_xml`): opakované použití nezdvojí, platný formulář nese vztah
     * a příprava vlastního řádného hlášení za měsíc pak ví, že už odešlo.
     */
    public function testUploadedReportsLandInExternalSubmissionHistory(): void
    {
        [, $employmentId] = $this->registerEmployee(withIdentifiers: true);
        $person = ['oic' => $this->oic, 'id_ppv' => $this->idPpv];
        $files = [
            $this->file('radne.xml', JmhzReportFixtures::report([JmhzReportFixtures::person($person)], 2026, 1)),
            $this->file('opravne.xml', JmhzReportFixtures::report([JmhzReportFixtures::person($person)], 2026, 1,
                ['type' => 'O', 'filled_at' => '2026-02-20T08:00:00Z'])),
        ];
        $keys = array_column($this->imports->preview($this->supplierId, 'test', $files)['records'], 'key');

        $this->apply($files, $keys);
        $this->apply($files, $keys);

        $rows = $this->db->pdo()->prepare(
            "SELECT s.environment, s.period, s.submission_type, s.status, s.file_name, s.form_count,
                    (SELECT COUNT(*) FROM payroll_external_jmhz_submission_forms f
                      WHERE f.supplier_id = s.supplier_id AND f.submission_id = s.id AND f.employment_id = ?) AS linked
               FROM payroll_external_jmhz_submissions s
              WHERE s.supplier_id = ? AND s.source = 'jmhz_xml' ORDER BY s.file_name"
        );
        $rows->execute([$employmentId, $this->supplierId]);
        self::assertSame([
            ['environment' => 'test', 'period' => '2026-01', 'submission_type' => 'O', 'status' => 'sent', 'file_name' => 'opravne.xml', 'form_count' => 1, 'linked' => 1],
            ['environment' => 'test', 'period' => '2026-01', 'submission_type' => 'R', 'status' => 'sent', 'file_name' => 'radne.xml', 'form_count' => 1, 'linked' => 0],
        ], array_map(static fn (array $r): array => array_merge($r, ['form_count' => (int) $r['form_count'], 'linked' => (int) $r['linked']]),
            $rows->fetchAll(\PDO::FETCH_ASSOC)));
        $store = $this->container->get(\MyInvoice\Service\Payroll\Import\Jmhz\JmhzExternalSubmissionStore::class);
        self::assertSame('O', $store->sentMonthly($this->supplierId, 'test', '2026-01')['submission_type'] ?? null);
        self::assertNull($store->sentMonthly($this->supplierId, 'production', '2026-01'));
    }

    public function testCancelledFormImportsNothing(): void
    {
        [$employeeId] = $this->registerEmployee(withIdentifiers: true);
        $files = [
            $this->file('radne.xml', JmhzReportFixtures::report([JmhzReportFixtures::person([
                'oic' => $this->oic,
                'id_ppv' => $this->idPpv,
            ])], 2026, 1)),
            $this->file('storno.xml', JmhzReportFixtures::submissionCancellation(2026, 1, 1, '2026-02-25T08:00:00Z')),
        ];

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        self::assertSame(0, $preview['files'][1]['record_count']);
        self::assertSame('S', $preview['files'][1]['submission_type']);
        $record = $preview['records'][0];
        self::assertSame('none', $record['operation']);
        self::assertFalse($record['selectable']);
        self::assertStringContainsString('stornovalo', implode(' ', $record['warnings']));
        self::assertSame([], $preview['opening_balances']);

        $applied = $this->apply($files, [$record['key']], openings: true);
        self::assertSame('skipped', $applied['results'][0]['status']);
        self::assertSame(0, $this->tableRows('payroll_person_tax_declarations'));
        self::assertSame([], $this->container->get(PayrollOpeningBalanceService::class)
            ->current($this->supplierId, $employeeId, 2026)['months']);
    }

    /**
     * PAM-14: schválená mzda roku převzetí počátečních stavů NEBLOKUJE —
     * u firmy, která přešla v průběhu roku, se převzatá čísla dolaďují.
     * Blokuje až to, co odešlo ven; tady vydaný roční doklad zaměstnance.
     */
    public function testOpeningBalancesAreBlockedOnlyAfterTheYearIsReported(): void
    {
        [$employeeId] = $this->registerEmployee(withIdentifiers: true);
        $files = [];
        foreach ([1, 2, 3] as $month) {
            $files[] = $this->file("jmhz-{$month}.xml", JmhzReportFixtures::report([JmhzReportFixtures::person([
                'oic' => $this->oic,
                'id_ppv' => $this->idPpv,
            ])], 2026, $month));
        }
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $pdo->prepare(
                "INSERT INTO payroll_statutory_accumulator_entries
                    (supplier_id, employee_id, tax_year, period_start, revision_id, calculation_kind,
                     values_json, source_result_hash, record_hash)
                 VALUES (?, ?, 2026, '2026-04-01', 1, 'income_tax', '{}', ?, ?)"
            )->execute([$this->supplierId, $employeeId, str_repeat('a', 64), str_repeat('b', 64)]);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $opening = $this->imports->preview($this->supplierId, 'test', $files)['opening_balances'][0];
        self::assertSame('ready', $opening['status'], 'Schválená mzda sama převzetí neblokuje.');
        self::assertCount(3, $opening['months']);

        $manifest = '{"sources":[]}';
        $pdo->prepare(
            'INSERT INTO payroll_annual_document_revisions
                (supplier_id, employee_id, tax_year, purpose, revision_no,
                 snapshot_ciphertext, snapshot_hash, source_manifest_json,
                 source_manifest_hash, approved_at)
             VALUES (?, ?, 2026, "taxable_income_advance_certificate", 1, ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $employeeId,
            'synteticky-sifrovany-snapshot',
            hash('sha256', 'snapshot2026'),
            $manifest,
            hash('sha256', $manifest),
        ]);

        $opening = $this->imports->preview($this->supplierId, 'test', $files)['opening_balances'][0];
        self::assertSame('blocked', $opening['status']);
        self::assertStringContainsString('roční doklad', (string) $opening['reason']);

        $applied = $this->apply($files, [], openings: true);
        self::assertSame(0, $applied['opening_balances']['saved']);
        self::assertStringContainsString('roční doklad', (string) $applied['opening_balances']['skipped'][0]['reason']);
    }

    public function testMissingMonthBlocksOpeningBalances(): void
    {
        $this->registerEmployee(withIdentifiers: true);
        $files = [];
        foreach ([1, 3] as $month) {
            $files[] = $this->file("jmhz-{$month}.xml", JmhzReportFixtures::report([JmhzReportFixtures::person([
                'oic' => $this->oic,
                'id_ppv' => $this->idPpv,
            ])], 2026, $month));
        }

        $opening = $this->imports->preview($this->supplierId, 'test', $files)['opening_balances'][0];

        self::assertSame('blocked', $opening['status']);
        self::assertStringContainsString('2026-02', (string) $opening['reason']);
    }

    public function testBrokenAndForeignFilesAreReportedWhileOthersAreProcessed(): void
    {
        $valid = JmhzReportFixtures::report([JmhzReportFixtures::person()], 2026, 2);
        $preview = $this->imports->preview($this->supplierId, 'test', [
            $this->file('ok.xml', $valid),
            $this->file('rozbite.xml', (string) preg_replace('#\s*<form:mzdaZuctovana>\d+</form:mzdaZuctovana>#', '', $valid, 1)),
            $this->file('cizi.xml', '<?xml version="1.0"?><jmhz xmlns="urn:example:other"/>'),
            $this->file('stara-verze.xml', (string) preg_replace(
                '#\s*<form:pocetDnu>\d+</form:pocetDnu>#',
                '',
                JmhzReportFixtures::report([JmhzReportFixtures::person()], 2026, 3),
                1,
            )),
        ]);

        self::assertNull($preview['files'][0]['error']);
        self::assertSame('JMHZ', $preview['files'][1]['document_type']);
        self::assertStringContainsString('neodpovídá schématu', (string) $preview['files'][1]['error']);
        self::assertStringContainsString('ani měsíční hlášení JMHZ', (string) $preview['files'][2]['error']);
        self::assertNull($preview['files'][3]['error']);
        self::assertStringContainsString('převzal s varováním', implode(' ', $preview['files'][3]['warnings']));
        self::assertCount(3, $preview['records']);
        // Osoba z hlášení v evidenci není: dávka ji dokládá odvozenou větou.
        $derived = $preview['records'][2];
        self::assertSame('JMHZ_DERIVED', $derived['document_type'], $this->dump($derived));
        self::assertSame('create_person', $derived['operation']);
    }

    public function testActionPassesPairsAndFlagsThrough(): void
    {
        [, $employmentId] = $this->registerEmployee(withIdentifiers: false);
        $action = $this->container->get(PayrollRegistrationImportAction::class);
        $files = [$this->file('jmhz-2.xml', JmhzReportFixtures::report([JmhzReportFixtures::person([
            'oic' => $this->oic,
            'id_ppv' => $this->idPpv,
        ])], 2026, 2))];

        $preview = $action->preview($this->request(['environment' => 'test', 'files' => $files]), new Response());
        if ($preview->getStatusCode() === 403) {
            self::markTestSkipped('Mzdový modul není v téhle instalaci licencovaný.');
        }
        self::assertSame(200, $preview->getStatusCode(), (string) $preview->getBody());
        $body = $this->json($preview);
        self::assertArrayHasKey('employment_options', $body);
        self::assertArrayHasKey('opening_balances', $body);
        self::assertArrayHasKey('averages', $body);
        $key = $body['records'][0]['key'];

        $applied = $action->apply($this->request([
            'environment' => 'test',
            'files' => $files,
            'keys' => [$key],
            'pairs' => [['key' => $key, 'employment_id' => $employmentId]],
            'evidence_confirmed' => true,
            'apply_opening_balances' => false,
        ]), new Response());
        self::assertSame(200, $applied->getStatusCode(), (string) $applied->getBody());
        $result = $this->json($applied);
        self::assertSame('applied', $result['results'][0]['status'], (string) $result['results'][0]['message']);
        self::assertSame(['saved' => 0, 'skipped' => []], $result['opening_balances']);
    }

    /**
     * Dítě „N" (zvýhodnění uplatňuje druhý rodič) dostane nejnižší pořadí,
     * které nepoužívá uplatňované dítě, a nárok nese jmenovanou jinou osobu.
     */
    public function testChildClaimedByOtherGetsFreeOrderAndNamedCaregiver(): void
    {
        [$employeeId] = $this->registerEmployee(withIdentifiers: true);
        $files = [$this->file('jmhz-1.xml', JmhzReportFixtures::report([JmhzReportFixtures::person([
            'oic' => $this->oic,
            'id_ppv' => $this->idPpv,
            'children' => [
                [
                    'identity' => ['given_name' => 'Adam', 'family_name' => 'Testovací', 'birth_date' => '2015-02-10'],
                    'ztp_p' => false,
                    'order' => 'N',
                ],
                [
                    'identity' => ['given_name' => 'Bára', 'family_name' => 'Testovací', 'birth_date' => '2019-09-01'],
                    'ztp_p' => false,
                    'order' => '1',
                ],
            ],
            'other_caregivers' => [['given_name' => 'Petr', 'family_name' => 'Testovací', 'birth_date' => '1988-03-03']],
        ])], 2026, 1))];

        $key = $this->imports->preview($this->supplierId, 'test', $files)['records'][0]['key'];
        $result = $this->apply($files, [$key])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertNull($result['message'], (string) $result['message']);

        $claims = [];
        foreach ($this->container->get(PayrollDependantRepository::class)
            ->overview($this->supplierId, $employeeId, '2026-01-01')['dependants'] as $dependant) {
            $claim = $dependant['claims'][0];
            $claims[$dependant['given_name']] = [
                $claim['child_order'],
                $claim['credit_status'],
                $claim['other_claimant_excluded'],
                $claim['other_household_caregiver_status'],
                $claim['other_caregiver_given_name'],
                $claim['other_caregiver_birth_date'],
            ];
        }
        ksort($claims);
        self::assertSame([
            'Adam' => [2, 'claimed_by_other', false, 'present', 'Petr', '1988-03-03'],
            'Bára' => [1, 'claimed', true, 'present', 'Petr', '1988-03-03'],
        ], $claims);
    }

    /**
     * Dítě narozené v průběhu měsíce hlášení: měsíc narození do nároku patří
     * (§ 35c odst. 10 ZDP), import ho proto nesmí odsunout na další měsíc.
     */
    public function testChildBornDuringTheReportedMonthIsClaimedForThatMonth(): void
    {
        [$employeeId] = $this->registerEmployee(withIdentifiers: true);
        $files = [$this->file('jmhz-5.xml', JmhzReportFixtures::report([JmhzReportFixtures::person([
            'oic' => $this->oic,
            'id_ppv' => $this->idPpv,
            'children' => [[
                'identity' => ['given_name' => 'Cyril', 'family_name' => 'Testovací', 'birth_date' => '2026-05-15'],
                'ztp_p' => false,
                'order' => '1',
            ]],
            'other_caregivers' => [],
        ])], 2026, 5))];

        $key = $this->imports->preview($this->supplierId, 'test', $files)['records'][0]['key'];
        $result = $this->apply($files, [$key])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);

        $dependants = $this->container->get(PayrollDependantRepository::class)
            ->overview($this->supplierId, $employeeId, '2026-05-01')['dependants'];
        self::assertCount(1, $dependants);
        self::assertSame('2026-05-15', $dependants[0]['existence_from']);
        self::assertCount(1, $dependants[0]['claims'], (string) $result['message']);
        self::assertSame('2026-05-01', $dependants[0]['claims'][0]['effective_from']);
        self::assertSame([], $dependants[0]['claims'][0]['blockers']);
    }

    /**
     * DAN-6: dítě osvojené (nebo převzaté do péče) v průběhu měsíce hlášení.
     * Měsíc osvojení do nároku patří (§ 35c odst. 10 ZDP), vztah k dítěti je
     * v evidenci. Import dřív posílal důvod nároku `null`, nárok nezapsal
     * a skončil varováním „zapište ručně".
     */
    public function testChildAdoptedDuringTheReportedMonthIsClaimedForThatMonth(): void
    {
        [$employeeId] = $this->registerEmployee(withIdentifiers: true);
        foreach ([['Dita', 'child_adopted', '2026-05-12'], ['Emil', 'child_in_care', '2026-05-20']] as [$name, $relation, $from]) {
            $this->container->get(PayrollDependantRepository::class)->createDependant(
                $this->supplierId,
                $employeeId,
                $this->container->get(PayrollDependantValidator::class)->validateDependant([
                    'relation' => $relation,
                    'full_name' => "{$name} Testovací",
                    'given_name' => $name,
                    'family_name' => 'Testovací',
                    'birth_date' => '2020-03-03',
                    'birth_number' => null,
                    'ztp_p' => false,
                    'student' => false,
                    'existence_from' => $from,
                    'existence_to' => null,
                    'note' => null,
                ]),
                '2026-05-01',
                $this->userId,
                null,
                null,
            );
        }
        $files = [$this->file('jmhz-5.xml', JmhzReportFixtures::report([JmhzReportFixtures::person([
            'oic' => $this->oic,
            'id_ppv' => $this->idPpv,
            'children' => [
                [
                    'identity' => ['given_name' => 'Dita', 'family_name' => 'Testovací', 'birth_date' => '2020-03-03'],
                    'ztp_p' => false,
                    'order' => '1',
                ],
                [
                    'identity' => ['given_name' => 'Emil', 'family_name' => 'Testovací', 'birth_date' => '2020-03-03'],
                    'ztp_p' => false,
                    'order' => '2',
                ],
            ],
            'other_caregivers' => [],
        ])], 2026, 5))];

        $key = $this->imports->preview($this->supplierId, 'test', $files)['records'][0]['key'];
        $result = $this->apply($files, [$key])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertStringNotContainsString('zapište ručně', (string) $result['message']);

        $claims = [];
        foreach ($this->container->get(PayrollDependantRepository::class)
            ->overview($this->supplierId, $employeeId, '2026-05-01')['dependants'] as $dependant) {
            self::assertCount(1, $dependant['claims'], (string) $result['message']);
            $claims[$dependant['given_name']] = [
                $dependant['claims'][0]['effective_from'],
                $dependant['claims'][0]['claim_reason'],
                $dependant['claims'][0]['blockers'],
            ];
        }
        ksort($claims);
        self::assertSame([
            'Dita' => ['2026-05-01', 'adoption', []],
            'Emil' => ['2026-05-01', 'foster_care', []],
        ], $claims);
    }

    /**
     * Výkon: import dávky hlášení nesmí formuláře plánovat znovu a znovu nad
     * nezměněnou evidencí ani pro každý formulář téže osoby číst tytéž údaje.
     * Měří se počet dotazů SELECT v relaci, ne čas.
     */
    public function testBatchPlanningDoesNotRepeatLookups(): void
    {
        $this->registerEmployee(withIdentifiers: true);
        $files = [];
        foreach ([1, 2, 3] as $month) {
            $files[] = $this->file("jmhz-{$month}.xml", JmhzReportFixtures::report([JmhzReportFixtures::person([
                'oic' => $this->oic,
                'id_ppv' => $this->idPpv,
            ])], 2026, $month));
        }

        $before = $this->selects();
        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        $previewSelects = $this->selects() - $before;
        $keys = array_column($preview['records'], 'key');

        $before = $this->selects();
        $first = $this->apply($files, $keys, openings: true, averages: true);
        $applySelects = $this->selects() - $before;
        self::assertSame('applied', $first['results'][0]['status'], (string) $first['results'][0]['message']);

        $before = $this->selects();
        $again = $this->apply($files, $keys, openings: true, averages: true);
        $repeatSelects = $this->selects() - $before;
        self::assertSame(['skipped', 'skipped', 'skipped'], array_column($again['results'], 'status'));
        self::assertSame('complete', $again['outcome']);

        // Před opravou: náhled 150, první použití 827, opakované použití 583
        // (každý formulář naplánovaný 5–6× a pro každý měsíc znovu tytéž vztahy).
        self::assertLessThan(120, $previewSelects);
        self::assertLessThan(600, $applySelects);
        // Opakované použití nemá co zapsat: nesmí stát víc než dva náhledy.
        self::assertLessThanOrEqual(2 * $previewSelects, $repeatSelects, "náhled {$previewSelects}, opakované použití {$repeatSelects}");
    }

    private function selects(): int
    {
        $row = $this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Com_select'")?->fetch(\PDO::FETCH_NUM);

        return (int) ($row[1] ?? 0);
    }

    /** @return array{0:int,1:int} [employee_id, employment_id] */
    private function registerEmployee(bool $withIdentifiers): array
    {
        $options = [
            'bno' => RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1),
            'start' => '2026-01-01',
        ];
        if ($withIdentifiers) {
            $options += ['ikmpsv' => $this->oic, 'oid' => $this->idPpv];
        }
        $files = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1($options))];
        $key = $this->imports->preview($this->supplierId, 'test', $files)['records'][0]['key'];
        $result = $this->apply($files, [$key])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);

        return [(int) $result['employee_id'], (int) $result['employment_id']];
    }

    /**
     * @param list<array{name:string,content_base64:string}> $files
     * @param list<string> $keys
     * @param list<array{key:string,employment_id:int}>|null $pairs
     * @return array<string,mixed>
     */
    private function apply(
        array $files,
        array $keys,
        ?array $pairs = null,
        bool $openings = false,
        bool $averages = false,
        bool $autoChanges = false,
        bool $autoAverages = false,
    ): array {
        return $this->imports->apply(
            $this->supplierId,
            'test',
            $files,
            $keys,
            true,
            null,
            $this->userId,
            null,
            'jmhz-import-test',
            $pairs,
            $openings,
            $averages,
            $autoChanges,
            $autoAverages,
        );
    }

    /** @return array<string,array<string,int>> fáze => stav => počet */
    private function checklistStatuses(int $employmentId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT phase, status, COUNT(*) AS items
               FROM payroll_employment_checklist_items
              WHERE supplier_id = ? AND employment_id = ?
              GROUP BY phase, status'
        );
        $statement->execute([$this->supplierId, $employmentId]);
        $statuses = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $statuses[(string) $row['phase']][(string) $row['status']] = (int) $row['items'];
        }

        return $statuses;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $fields
     * @return list<list<mixed>>
     */
    private function rows(array $rows, array $fields): array
    {
        return array_map(
            static fn (array $row): array => [
                ...array_map(static fn (string $field): mixed => $row[$field], $fields),
                (string) $row['effective_from'],
                $row['effective_to'] === null ? null : (string) $row['effective_to'],
                $row['evidence_reference'] ?? null,
            ],
            $rows,
        );
    }

    /** @return array{name:string,content_base64:string} */
    private function file(string $name, string $content): array
    {
        return ['name' => $name, 'content_base64' => base64_encode($content)];
    }

    private function tableRows(string $table): int
    {
        $statement = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
        $statement->execute([$this->supplierId]);

        return (int) $statement->fetchColumn();
    }

    private function dump(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
