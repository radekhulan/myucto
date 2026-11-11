<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollStatutoryEvidenceBulkDefaultsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Service\Payroll\PayrollStatutoryEvidenceBulkDefaults;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunValidation;
use MyInvoice\Tests\Fixtures\Payroll\PayrollRunScaleFixture;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Hromadné doplnění výchozí zákonné evidence.
 *
 * Hlídá hlavně to, co hromadná akce NESMÍ: psát k osobě s cizím prvkem,
 * přepsat existující větu, sáhnout do schváleného období, vymyslet podpis
 * prohlášení nebo slevu důchodce tam, kde o ní nic nevíme. Pouze syntetická data.
 */
#[Group('integration')]
final class PayrollStatutoryEvidenceBulkDefaultsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const EFFECTIVE_ON = '2026-06-30';

    private Connection $db;
    private PayrollStatutoryEvidenceBulkDefaults $bulk;
    private PayrollStatutoryEvidenceBulkDefaultsAction $action;
    private PayrollPersonStatutoryEvidenceRepository $repository;
    private PayrollRunSnapshotBuilder $builder;
    private PayrollEmployerPolicyRepository $policies;
    private int $userId;
    private int $supplierId;
    private int $otherSupplierId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->bulk = $container->get(PayrollStatutoryEvidenceBulkDefaults::class);
            $this->action = $container->get(PayrollStatutoryEvidenceBulkDefaultsAction::class);
            $this->repository = $container->get(PayrollPersonStatutoryEvidenceRepository::class);
            $this->builder = $container->get(PayrollRunSnapshotBuilder::class);
            $this->policies = $container->get(PayrollEmployerPolicyRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasTable('payroll_person_social_discount_claims')
            || !$this->db->hasTable('payroll_person_foreign_permits')
        ) {
            $this->markTestSkipped('Mzdové migrace neproběhly.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
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

    public function testPersonWithoutForeignElementGetsTheThreeDefaults(): void
    {
        $employeeId = $this->person();

        $result = $this->apply([$employeeId]);

        self::assertSame(['applied' => 1, 'skipped' => 0, 'failed' => 0], $result['counts']);
        self::assertSame(
            ['tax_residences', 'social_jurisdictions', 'social_discount_claims'],
            $result['applied'][0]['sections'],
        );
        self::assertSame('2026-06-01', $result['applied'][0]['effective_from']);

        $view = $this->view($employeeId);
        $residence = $view['sections']['tax_residences'];
        self::assertCount(1, $residence);
        self::assertSame('czech-resident', $residence[0]['residence']);
        self::assertSame('CZ', $residence[0]['country_code']);
        self::assertSame('2026-06-01', $residence[0]['effective_from']);
        self::assertNull($residence[0]['effective_to']);
        self::assertNull($residence[0]['evidence_reference']);
        self::assertStringStartsWith('Doplněno hromadně účetní ', (string) $residence[0]['evidence_note']);
        self::assertStringEndsWith('výchozí stav bez cizího prvku', (string) $residence[0]['evidence_note']);

        $jurisdiction = $view['sections']['social_jurisdictions'];
        self::assertCount(1, $jurisdiction);
        self::assertSame('czech_regime_verified', $jurisdiction[0]['jurisdiction']);
        self::assertSame('not_applicable', $jurisdiction[0]['a1_status']);
        self::assertNull($jurisdiction[0]['a1_certificate_reference']);

        $discount = $view['sections']['social_discount_claims'];
        self::assertCount(1, $discount);
        self::assertSame('not_claimed', $discount[0]['status']);

        self::assertSame([], $view['sections']['tax_declarations']);
        self::assertSame([], $view['sections']['health_coverages']);
        self::assertSame(
            ['tax_declaration_evidence_missing', 'health_coverage_evidence_missing'],
            $view['blockers'],
        );
        self::assertSame(1, $this->activityCount('payroll.person_statutory_evidence.bulk_defaults'));
    }

    public function testForeignElementsExcludeThePerson(): void
    {
        $addressAbroad = $this->person();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city,
                 postal_code, country_code, effective_from)
             VALUES (?, ?, "residence", "Syntetická 1", "Bratislava", "81101", "SK", "2025-01-01")'
        )->execute([$this->supplierId, $addressAbroad]);

        $citizenship = $this->person(citizenship: 'SK');

        $a1 = $this->person();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, effective_from, planned_start_on,
                 actual_start_on, weekly_hours, workload_basis_points,
                 social_insurance_participation, health_insurance_participation,
                 tax_regime, tax_declaration_signed, is_primary, a1_certificate_until)
             VALUES (?, ?, "2025-01-01", "2025-01-01", "2025-01-01", 40, 10000,
                     "automatic", "automatic", "advance", 0, 1, "2026-12-31")'
        )->execute([$this->supplierId, $this->employmentOf($a1)]);

        $clean = $this->person();

        $preview = $this->bulk->preview(
            $this->supplierId,
            self::EFFECTIVE_ON,
            [$addressAbroad, $citizenship, $a1, $clean],
        );
        $people = $this->byEmployee($preview['people']);
        foreach ([
            $addressAbroad => 'address_abroad',
            $citizenship => 'foreign_citizenship',
            $a1 => 'a1_certificate',
        ] as $employeeId => $signal) {
            self::assertSame('excluded', $people[$employeeId]['status'], $signal);
            self::assertSame(['foreign_element'], $people[$employeeId]['reasons'], $signal);
            self::assertSame([$signal], $people[$employeeId]['foreign_elements']);
        }
        self::assertSame('ready', $people[$clean]['status']);
        self::assertSame([$clean], $preview['ready_employee_ids']);

        $result = $this->apply([$addressAbroad, $citizenship, $a1, $clean]);
        self::assertSame([$clean], array_column($result['applied'], 'employee_id'));
        self::assertSame(
            [$addressAbroad, $citizenship, $a1],
            array_column($result['skipped'], 'employee_id'),
        );
        foreach ([$addressAbroad, $citizenship, $a1] as $employeeId) {
            foreach (['payroll_person_tax_residences', 'payroll_person_social_jurisdictions', 'payroll_person_social_discount_claims'] as $table) {
                self::assertSame(0, $this->rowCount($table, $employeeId), $table);
            }
        }
    }

    public function testExistingSentenceIsNeverRewritten(): void
    {
        $employeeId = $this->person();
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_residences
                (supplier_id, employee_id, residence, country_code, effective_from,
                 evidence_reference, evidence_note)
             VALUES (?, ?, "czech-resident", "CZ", "2026-01-01", "document:tax-residence", "Ručně")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_social_jurisdictions
                (supplier_id, employee_id, jurisdiction, a1_status, effective_from)
             VALUES (?, ?, "unverified", "unverified", "2026-01-01")'
        )->execute([$this->supplierId, $employeeId]);
        $before = $this->view($employeeId)['sections'];

        $preview = $this->byEmployee(
            $this->bulk->preview($this->supplierId, self::EFFECTIVE_ON, [$employeeId])['people'],
        )[$employeeId];
        self::assertSame('exists', $preview['sections']['tax_residences']['state']);
        self::assertSame('exists', $preview['sections']['social_jurisdictions']['state']);
        self::assertSame('add', $preview['sections']['social_discount_claims']['state']);

        $result = $this->apply([$employeeId]);

        self::assertSame(['social_discount_claims'], $result['applied'][0]['sections']);
        $after = $this->view($employeeId)['sections'];
        self::assertSame($before['tax_residences'], $after['tax_residences']);
        self::assertSame($before['social_jurisdictions'], $after['social_jurisdictions']);
        self::assertSame(1, $after['tax_residences'][0]['row_version']);
    }

    /**
     * Rezidence a příslušnost skončily s první dohodou (28. 2.), osoba má další
     * dohodu od 19. 3. Hromadné doplnění za březen dřív sekce hlásilo jako
     * hotové a březen zůstal bez údajů. Doplní navazující větu bez konce,
     * skončenou nechá být.
     */
    public function testEndedSentenceIsFollowedByOpenEndedDefault(): void
    {
        $employeeId = $this->person(start: '2026-03-19');
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_residences
                (supplier_id, employee_id, residence, country_code, effective_from, effective_to)
             VALUES (?, ?, "czech-resident", "CZ", "2026-02-01", "2026-02-28")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_social_jurisdictions
                (supplier_id, employee_id, jurisdiction, a1_status, effective_from, effective_to)
             VALUES (?, ?, "czech_regime_verified", "not_applicable", "2026-02-01", "2026-02-28")'
        )->execute([$this->supplierId, $employeeId]);

        $preview = $this->byEmployee(
            $this->bulk->preview($this->supplierId, '2026-03-31', [$employeeId])['people'],
        )[$employeeId];
        self::assertSame('add', $preview['sections']['tax_residences']['state']);
        self::assertSame('add', $preview['sections']['social_jurisdictions']['state']);

        $result = $this->bulk->apply(
            $this->supplierId,
            '2026-03-31',
            [$employeeId],
            PayrollStatutoryEvidenceBulkDefaults::DEFAULT_SECTIONS,
            false,
            $this->userId,
            null,
            null,
        );

        self::assertSame(['applied' => 1, 'skipped' => 0, 'failed' => 0], $result['counts'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $sections = $this->view($employeeId)['sections'];
        foreach (['tax_residences', 'social_jurisdictions'] as $section) {
            $ranges = array_map(
                static fn (array $row): array => [$row['effective_from'], $row['effective_to']],
                $sections[$section],
            );
            usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            self::assertSame([['2026-02-01', '2026-02-28'], ['2026-03-01', null]], $ranges, $section);
        }
    }

    public function testApprovedPeriodIsNotWrittenInto(): void
    {
        $employeeId = $this->person();
        $leaver = $this->person(end: '2026-06-15');
        $this->approveRun('2026-06-01');

        $preview = $this->byEmployee(
            $this->bulk->preview($this->supplierId, self::EFFECTIVE_ON, [$employeeId, $leaver])['people'],
        );
        self::assertSame('2026-07-01', $preview[$employeeId]['effective_from']);
        self::assertSame('after_frozen_period', $preview[$employeeId]['effective_from_basis']);
        self::assertSame(['period_frozen'], $preview[$leaver]['reasons']);

        $result = $this->apply([$employeeId, $leaver]);

        self::assertSame('2026-07-01', $result['applied'][0]['effective_from']);
        self::assertSame(
            '2026-07-01',
            $this->view($employeeId)['sections']['tax_residences'][0]['effective_from'],
        );
        self::assertSame(0, $this->rowCount('payroll_person_tax_residences', $leaver));
    }

    /**
     * Zdravotní krytí s dokladem, které začalo ve schváleném období, musí po
     * hromadném zápisu zůstat jediným řádkem. Dokud se do věcného porovnání
     * počítal i otisk dokladu (klient ho nikdy neposílá), save() ho při
     * opětovném odeslání rozdělil na uzavřenou a novou verzi.
     */
    public function testDocumentLinkedCoverageInApprovedPeriodStaysUntouched(): void
    {
        $employeeId = $this->person();
        $sha256 = hash('sha256', 'bulk-defaults-health-document');
        $documentId = $this->document($sha256);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status, insurer_code,
                 insurer_evidence_reference, health_evidence_document_id,
                 health_evidence_document_sha256, effective_from)
             VALUES (?, ?, "czech_regime_verified", "verified", "111",
                     "document:health-insurer", ?, ?, "2026-01-01")'
        )->execute([$this->supplierId, $employeeId, $documentId, $sha256]);
        $this->approveRun('2026-06-01');
        $before = $this->view($employeeId)['sections']['health_coverages'];

        $result = $this->apply([$employeeId]);

        self::assertSame([], $result['failed']);
        self::assertSame(1, $result['counts']['applied']);
        self::assertSame($before, $this->view($employeeId)['sections']['health_coverages']);
    }

    public function testUnsignedDeclarationNeedsExplicitConfirmation(): void
    {
        $employeeId = $this->person();

        $this->apply([$employeeId]);
        self::assertSame(0, $this->rowCount('payroll_person_tax_declarations', $employeeId));

        $result = $this->bulk->apply(
            $this->supplierId,
            self::EFFECTIVE_ON,
            [$employeeId],
            [],
            true,
            $this->userId,
            null,
            null,
        );

        self::assertSame(['tax_declarations'], $result['applied'][0]['sections']);
        $declarations = $this->view($employeeId)['sections']['tax_declarations'];
        self::assertCount(1, $declarations);
        self::assertSame('not-signed', $declarations[0]['status']);
        self::assertNull($declarations[0]['evidence_reference']);
        self::assertStringContainsString('nepodepsáno', (string) $declarations[0]['evidence_note']);
        self::assertSame(['health_coverage_evidence_missing'], $this->view($employeeId)['blockers']);
    }

    public function testDeclarationSectionWithoutConfirmationIsRejected(): void
    {
        $employeeId = $this->person();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('record_unsigned_declaration');
        $this->bulk->apply(
            $this->supplierId,
            self::EFFECTIVE_ON,
            [$employeeId],
            ['tax_residences', 'tax_declarations'],
            false,
            $this->userId,
            null,
            null,
        );
    }

    public function testPensionerDiscountIsNotDefaultedFromSixty(): void
    {
        // 1. 6. 2026 je den účinnosti; první osoba má šedesát přesně ten den.
        $sixty = $this->person(birthDate: '1966-06-01');
        $fiftyNine = $this->person(birthDate: '1966-06-02');
        $unknown = $this->person(birthDate: null);

        $preview = $this->byEmployee(
            $this->bulk->preview($this->supplierId, self::EFFECTIVE_ON, [$sixty, $fiftyNine, $unknown])['people'],
        );
        self::assertSame(
            ['state' => 'excluded', 'reason' => 'age_60_or_more'],
            $preview[$sixty]['sections']['social_discount_claims'],
        );
        self::assertSame(
            ['state' => 'add', 'reason' => null],
            $preview[$fiftyNine]['sections']['social_discount_claims'],
        );
        self::assertSame(
            ['state' => 'excluded', 'reason' => 'birth_date_missing'],
            $preview[$unknown]['sections']['social_discount_claims'],
        );

        $applied = $this->byEmployee($this->apply([$sixty, $fiftyNine, $unknown])['applied']);

        self::assertSame(['tax_residences', 'social_jurisdictions'], $applied[$sixty]['sections']);
        self::assertSame(0, $this->rowCount('payroll_person_social_discount_claims', $sixty));
        self::assertSame(1, $this->rowCount('payroll_person_social_discount_claims', $fiftyNine));
        self::assertSame(0, $this->rowCount('payroll_person_social_discount_claims', $unknown));
        // Hromadná akce za šedesátníka „neuplatňuje se" nezapíše, ale chybějící
        // záznam výpočet nezastaví: slevu uplatňuje zaměstnanec sám.
        self::assertNotContains(
            'working_pensioner_discount_evidence_missing',
            $this->view($sixty)['blockers'],
        );
    }

    public function testPreviewNamesWithholdingRiskAndMissingInsurer(): void
    {
        $dpp = $this->person(relationType: 'dpp');
        $employment = $this->person();
        $insured = $this->person();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status, insurer_code,
                 effective_from)
             VALUES (?, ?, "czech_regime_verified", "verified", "111", "2026-01-01")'
        )->execute([$this->supplierId, $insured]);

        $preview = $this->bulk->preview(
            $this->supplierId,
            self::EFFECTIVE_ON,
            [$dpp, $employment, $insured],
        );

        $people = $this->byEmployee($preview['people']);
        self::assertTrue($people[$dpp]['unsigned_declaration_withholding_risk']);
        self::assertSame([$this->employmentOf($dpp)], $people[$dpp]['withholding_employment_ids']);
        // § 6 odst. 4 písm. b) ZDP: pracovní poměr s měsícem pod rozhodnou
        // částkou se bez prohlášení sráží taky. Dřív tu stálo assertFalse —
        // náhled pracovní poměr za rizikový nepovažoval.
        self::assertTrue($people[$employment]['unsigned_declaration_withholding_risk']);
        self::assertSame(
            [$dpp, $employment, $insured],
            array_column($preview['unsigned_declaration_withholding_risk'], 'employee_id'),
        );
        self::assertSame(
            [$dpp, $employment],
            array_column($preview['health_insurer_missing'], 'employee_id'),
        );
        self::assertSame(3, $preview['summary']['declaration_missing']);
        self::assertSame(2, $preview['summary']['health_insurer_missing']);
    }

    public function testPreviewWithoutIdsTakesThePeopleOfTheRunMonth(): void
    {
        $active = $this->person();
        $this->person(end: '2026-04-30');
        $this->person(start: '2026-08-01');

        $preview = $this->bulk->preview($this->supplierId, self::EFFECTIVE_ON, null);

        self::assertSame([$active], array_column($preview['people'], 'employee_id'));
        self::assertSame(1, $preview['summary']['ready']);
    }

    public function testLaterStartTakesEffectFromTheStartMonth(): void
    {
        $future = $this->person(start: '2026-08-17');

        $result = $this->apply([$future]);

        self::assertSame('2026-08-01', $result['applied'][0]['effective_from']);
    }

    public function testTenantIsolation(): void
    {
        $foreignTenantPerson = $this->person(supplierId: $this->otherSupplierId);

        $preview = $this->bulk->preview($this->supplierId, self::EFFECTIVE_ON, [$foreignTenantPerson]);
        self::assertSame(['employee_not_found'], $preview['people'][0]['reasons']);

        $result = $this->apply([$foreignTenantPerson]);
        self::assertSame(['employee_not_found'], $result['skipped'][0]['reasons']);
        foreach ([$this->supplierId, $this->otherSupplierId] as $supplierId) {
            $statement = $this->db->pdo()->prepare(
                'SELECT COUNT(*) FROM payroll_person_tax_residences WHERE supplier_id = ? AND employee_id = ?'
            );
            $statement->execute([$supplierId, $foreignTenantPerson]);
            self::assertSame(0, (int) $statement->fetchColumn());
        }
    }

    public function testActionRequiresSessionAndPersonWritePermission(): void
    {
        $employeeId = $this->person();
        $body = ['effective_on' => self::EFFECTIVE_ON, 'employee_ids' => [$employeeId]];

        $viewer = $this->action->apply(
            $this->request('/api/payroll/statutory-evidence/bulk-defaults/apply', $body, 'viewer'),
            new Response(),
        );
        self::assertSame(403, $viewer->getStatusCode());
        self::assertSame('forbidden', $this->json($viewer)['error']['code']);

        $bearer = $this->action->preview(
            $this->request('/api/payroll/statutory-evidence/bulk-defaults/preview', $body, 'accountant', 'bearer'),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);
        self::assertSame(0, $this->rowCount('payroll_person_tax_residences', $employeeId));

        $preview = $this->action->preview(
            $this->request('/api/payroll/statutory-evidence/bulk-defaults/preview', $body),
            new Response(),
        );
        self::assertSame(200, $preview->getStatusCode());
        self::assertSame([$employeeId], $this->json($preview)['preview']['ready_employee_ids']);
    }

    public function testActionTreatsOnlyLiteralTrueAsUnsignedConfirmation(): void
    {
        $employeeId = $this->person();

        $response = $this->action->apply(
            $this->request('/api/payroll/statutory-evidence/bulk-defaults/apply', [
                'effective_on' => self::EFFECTIVE_ON,
                'employee_ids' => [$employeeId],
                'record_unsigned_declaration' => '1',
            ]),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('payroll_person_tax_residences', $employeeId));
        self::assertSame(0, $this->rowCount('payroll_person_tax_declarations', $employeeId));
    }

    /**
     * Nepodepsané prohlášení assembler neblokuje (je to rozhodnutý stav), ale
     * mzdový běh o něm má říct jednou větou za firmu — ne mlčet a ne hlásit
     * každou osobu zvlášť.
     */
    public function testRunSnapshotWarnsOnceAboutUnsignedDeclarations(): void
    {
        $this->policies->create($this->supplierId, [
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'payday_day' => 10,
            'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day',
            'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'not_used',
            'travel_expense_policy' => 'not_used',
            'leave_entitlement_weeks' => 4,
            'automatic_posting_enabled' => true,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:bulk-defaults-policy',
        ], $this->userId);
        $fixture = new PayrollRunScaleFixture($this->db, $this->supplierId, $this->userId, 7_950_000_000);
        // Osoba s indexem 0 prohlášení nemá, osoba s indexem 1 má podepsané.
        $fixture->seed(3);

        self::assertSame([], $this->summaryWarnings());

        foreach ([$fixture->employeeIds[0], $fixture->employeeIds[2]] as $employeeId) {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_person_tax_declarations
                    (supplier_id, employee_id, status, effective_from)
                 VALUES (?, ?, "not-signed", "2026-01-01")'
            )->execute([$this->supplierId, $employeeId]);
        }

        $warnings = $this->summaryWarnings();
        self::assertCount(1, $warnings);
        self::assertSame('warning', $warnings[0]->severity);
        self::assertSame('run', $warnings[0]->entityType);
        self::assertNull($warnings[0]->entityId);
        self::assertFalse($warnings[0]->requiresOverride);
        self::assertStringContainsString('prohlášením poplatníka k dani: 2.', $warnings[0]->message);
    }

    /**
     * Jednatel nebo společník bez podepsaného prohlášení je vědomý stav, běh
     * o něm nemá varovat. Osoba, která má vedle funkce ještě jiný vztah, se
     * počítá dál.
     */
    public function testRunSnapshotSkipsCompanyBodiesInUnsignedDeclarationWarning(): void
    {
        $this->policies->create($this->supplierId, [
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'payday_day' => 10,
            'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day',
            'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'not_used',
            'travel_expense_policy' => 'not_used',
            'leave_entitlement_weeks' => 4,
            'automatic_posting_enabled' => true,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:bulk-defaults-policy',
        ], $this->userId);
        $fixture = new PayrollRunScaleFixture($this->db, $this->supplierId, $this->userId, 7_960_000_000);
        // Osoba 0 má dva vztahy, osoba 2 jeden.
        $fixture->seed(3);
        foreach ([$fixture->employeeIds[0], $fixture->employeeIds[2]] as $employeeId) {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_person_tax_declarations
                    (supplier_id, employee_id, status, effective_from)
                 VALUES (?, ?, "not-signed", "2026-01-01")'
            )->execute([$this->supplierId, $employeeId]);
        }
        $relation = $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET relation_type = ? WHERE supplier_id = ? AND employee_id = ?'
        );
        $relation->execute(['statutory_body', $this->supplierId, $fixture->employeeIds[2]]);
        $oneOfTwo = $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET relation_type = "partner_dependent"
              WHERE supplier_id = ? AND employee_id = ? ORDER BY id LIMIT 1'
        );
        $oneOfTwo->execute([$this->supplierId, $fixture->employeeIds[0]]);

        $warnings = $this->summaryWarnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString('prohlášením poplatníka k dani: 1.', $warnings[0]->message);

        $relation->execute(['partner_dependent', $this->supplierId, $fixture->employeeIds[0]]);
        self::assertSame([], $this->summaryWarnings());
    }

    // --- pomocníci ---------------------------------------------------------

    /** @return list<PayrollRunValidation> */
    private function summaryWarnings(): array
    {
        $snapshot = $this->builder->build(
            $this->supplierId,
            PayrollRunScaleFixture::PERIOD_START,
            PayrollRunScaleFixture::PAYMENT_DATE,
        );

        return array_values(array_filter(
            $snapshot->validations,
            static fn (PayrollRunValidation $validation): bool =>
                $validation->code === 'tax_declaration_not_signed_summary',
        ));
    }

    /**
     * @param list<int> $employeeIds
     * @return array<string,mixed>
     */
    private function apply(array $employeeIds): array
    {
        return $this->bulk->apply(
            $this->supplierId,
            self::EFFECTIVE_ON,
            $employeeIds,
            PayrollStatutoryEvidenceBulkDefaults::DEFAULT_SECTIONS,
            false,
            $this->userId,
            null,
            null,
        );
    }

    /** @return array<string,mixed> */
    private function view(int $employeeId): array
    {
        $view = $this->repository->editorView($this->supplierId, $employeeId, self::EFFECTIVE_ON);
        self::assertNotNull($view);

        return $view;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function byEmployee(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['employee_id']] = $row;
        }

        return $result;
    }

    private function person(
        ?string $birthDate = '1990-04-15',
        ?string $citizenship = null,
        string $relationType = 'employment',
        string $start = '2025-01-01',
        ?string $end = null,
        ?int $supplierId = null,
    ): int {
        $supplierId ??= $this->supplierId;
        $pdo = $this->db->pdo();
        $name = 'Syntetická osoba ' . (++$this->sequence);
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        )->execute([$supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, birth_date,
                 citizenship_country_code, effective_from)
             VALUES (?, ?, ?, ?, ?, "2000-01-01")'
        )->execute([$supplierId, $employeeId, $name, $birthDate, $citizenship]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, end_date, is_primary)
             VALUES (?, ?, ?, ?, "active", ?, ?, ?, 1)'
        )->execute([
            $supplierId,
            $employeeId,
            'BULK-' . $this->sequence,
            $relationType,
            $start,
            $start,
            $end,
        ]);

        return $employeeId;
    }

    private function employmentOf(int $employeeId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? AND employee_id = ?'
        );
        $statement->execute([$this->supplierId, $employeeId]);

        return (int) $statement->fetchColumn();
    }

    private function rowCount(string $table, int $employeeId): int
    {
        $statement = $this->db->pdo()->prepare(sprintf(
            'SELECT COUNT(*) FROM %s WHERE supplier_id = ? AND employee_id = ?',
            $table,
        ));
        $statement->execute([$this->supplierId, $employeeId]);

        return (int) $statement->fetchColumn();
    }

    private function activityCount(string $action): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = ?'
        );
        $statement->execute([$this->supplierId, $action]);

        return (int) $statement->fetchColumn();
    }

    private function approveRun(string $periodStart): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, ?, "approved", 1)'
        )->execute([$this->supplierId, $periodStart, substr($periodStart, 0, 8) . '15']);
        $runId = (int) $pdo->lastInsertId();
        $snapshot = json_encode(['schema_version' => 'payroll-run-input.v2'], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash,
                 approved_at)
             VALUES (?, ?, 1, "approved", "payroll-run-input.v2", ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('c', 64),
            $snapshot,
            hash('sha256', $snapshot),
            $snapshot,
            hash('sha256', $snapshot),
            random_bytes(32),
        ]);
    }

    private function document(string $sha256): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO documents
                (supplier_id, title, original_name, filename, sha256, mime_type,
                 size_bytes, doc_type, source, uploaded_by, scope)
             VALUES (?, "Syntetický zdravotní důkaz", "health-evidence.pdf", ?, ?,
                     "application/pdf", 1, "pdf", "manual", ?, "company")'
        )->execute([$this->supplierId, $sha256 . '.pdf', $sha256, $this->userId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $body */
    private function request(
        string $path,
        array $body,
        string $role = 'accountant',
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
