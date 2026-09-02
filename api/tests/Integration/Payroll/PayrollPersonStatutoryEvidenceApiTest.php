<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollPersonStatutoryEvidenceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponent;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxResult;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationEvidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidenceEvidence;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Zapisovací cesta k zákonné evidenci osoby.
 *
 * Tabulky migrace 1256 měly do téhle chvíle jen čtecí cestu — `INSERT` do nich
 * dělaly výhradně testy. Tenhle test proto hlídá nejen tvar dat, ale hlavně to,
 * že se přes API dají založit a že se pak objeví ve snímku mzdového běhu.
 */
#[Group('integration')]
final class PayrollPersonStatutoryEvidenceApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    /** Měsíční základní sleva na poplatníka podle rulesetu 2026 (2 570 Kč). */
    private const TAXPAYER_CREDIT_MINOR_UNITS = 257_000;

    private Connection $db;
    private PayrollPersonStatutoryEvidenceAction $action;
    private PayrollPersonStatutoryEvidenceRepository $repository;
    private int $userId;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $otherEmployeeId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollPersonStatutoryEvidenceAction::class);
            $this->repository = $container->get(
                PayrollPersonStatutoryEvidenceRepository::class,
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        if (!$this->db->hasTable('payroll_person_social_discount_claims')) {
            $this->markTestSkipped('Migrace 1256 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);

        $this->employeeId = $this->createEmployee($this->supplierId);
        $this->otherEmployeeId = $this->createEmployee($this->otherSupplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * `EDITABLE_SECTIONS` je párovací konstanta pro klientský union; PHP ji
     * z klíčů `EDITABLE` odvodit neumí, takže shodu musí hlídat test — jinak
     * by přidaná sekce tiše chyběla v API i v UI.
     */
    public function testEditableSectionsMatchTheEditorPayload(): void
    {
        $view = $this->json($this->show())['evidence'];

        self::assertSame(
            PayrollPersonStatutoryEvidenceRepository::EDITABLE_SECTIONS,
            array_keys($view['sections']),
        );
    }

    public function testEmptyEvidenceNamesTheSameBlockersAsThePayrollRun(): void
    {
        $body = $this->json($this->show());

        self::assertSame([], $body['evidence']['sections']['tax_declarations']);
        self::assertEqualsCanonicalizing(
            [
                'tax_declaration_evidence_missing',
                'tax_residence_evidence_missing',
                'social_jurisdiction_evidence_missing',
                'working_pensioner_discount_evidence_missing',
                'health_coverage_evidence_missing',
            ],
            $body['evidence']['blockers'],
        );
    }

    public function testCompleteEvidenceIsStoredAndClearsTheBlockers(): void
    {
        $response = $this->save($this->completeEvidence());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $evidence = $this->json($response)['evidence'];
        self::assertSame([], $evidence['blockers']);
        self::assertCount(1, $evidence['sections']['tax_declarations']);
        self::assertSame('signed', $evidence['sections']['tax_declarations'][0]['status']);
        self::assertSame(
            'Podepsáno na papíře, uloženo ve složce zaměstnance',
            $evidence['sections']['tax_declarations'][0]['evidence_note'],
        );

        // A hlavně: co se zapsalo, musí vidět i snímek mzdového běhu.
        $snapshot = $this->repository->snapshot(
            $this->supplierId,
            $this->employeeId,
            '2026-08-31',
        );
        self::assertIsArray($snapshot);
        self::assertSame('signed', $snapshot['income_tax']['declaration']['status']);
        self::assertSame('czech-resident', $snapshot['income_tax']['residence']['residence']);
        self::assertSame(
            'czech_regime_verified',
            $snapshot['social']['jurisdiction']['jurisdiction'],
        );
        self::assertSame(
            'not_claimed',
            $snapshot['social']['working_pensioner_discount']['status'],
        );
        self::assertSame('111', $snapshot['health']['coverage']['insurer_code']);
        self::assertSame(
            'employer_obstacle_verified',
            $snapshot['health']['month_evidence']['top_up_responsibility'],
        );
    }

    /**
     * Zaevidovaná sleva na poplatníka musí dojít až do zálohy na daň.
     *
     * Do téhle chvíle do `payroll_person_tax_credit_claims` nevedla zapisovací
     * cesta, takže `MonthlyEmploymentIncomeTaxCalculator` nikdy žádnou slevu
     * nedostal a každý zaměstnanec s podepsaným prohlášením platil o měsíční
     * slevu vyšší zálohu. Test proto jde celou cestou: zápis přes API →
     * snímek mzdového běhu → výpočet.
     */
    public function testTaxpayerCreditIsStoredAndLowersTheMonthlyAdvance(): void
    {
        $payload = $this->completeEvidence();
        $payload['sections']['tax_credit_claims'] = [[
            'credit_kind' => 'taxpayer',
            'evidence_status' => 'verified',
            'evidence_reference' => 'credit:38k-taxpayer-claim',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]];

        $response = $this->save($payload);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $stored = $this->json($response)['evidence']['sections']['tax_credit_claims'];
        self::assertCount(1, $stored);
        self::assertSame('taxpayer', $stored[0]['credit_kind']);
        self::assertSame('verified', $stored[0]['evidence_status']);

        $snapshot = $this->repository->snapshot(
            $this->supplierId,
            $this->employeeId,
            '2026-08-31',
        );
        self::assertIsArray($snapshot);
        $claims = $snapshot['income_tax']['credit_claims'];
        self::assertCount(1, $claims);
        self::assertSame('taxpayer', $claims[0]['credit_kind']);

        $withCredit = $this->monthlyIncomeTax($claims);
        $withoutCredit = $this->monthlyIncomeTax([]);

        self::assertSame([], $withCredit->issues);
        self::assertSame(TaxCalculationStatus::Calculated, $withCredit->status);
        // § 35ba odst. 1 písm. a) — 30 840 Kč ročně, tedy 2 570 Kč měsíčně.
        self::assertSame(self::TAXPAYER_CREDIT_MINOR_UNITS, $withCredit->claimedNonRefundableCreditsMinorUnits);
        self::assertSame(
            ['taxpayer' => self::TAXPAYER_CREDIT_MINOR_UNITS],
            $withCredit->claimedNonRefundableCreditBreakdown,
        );
        self::assertNotNull($withCredit->advanceTax);
        self::assertNotNull($withoutCredit->advanceTax);
        self::assertSame(
            $withoutCredit->advanceTax->taxAfterCreditsMinorUnits
                - self::TAXPAYER_CREDIT_MINOR_UNITS,
            $withCredit->advanceTax->taxAfterCreditsMinorUnits,
        );
    }

    /**
     * Vazbu na podepsané prohlášení hlídá kalkulátor sám (§ 38k odst. 4);
     * evidence slevy ji nezdvojuje. Test je tu proto, aby bylo vidět, že
     * uložená sleva bez prohlášení zálohu NESNÍŽÍ a řekne proč.
     */
    public function testCreditWithoutSignedDeclarationDoesNotLowerTheAdvance(): void
    {
        $payload = $this->completeEvidence();
        $payload['sections']['tax_declarations'][0]['status'] = 'not-signed';
        $payload['sections']['tax_declarations'][0]['evidence_reference']
            = 'declaration:38k-not-signed';
        $payload['sections']['tax_credit_claims'] = [[
            'credit_kind' => 'taxpayer',
            'evidence_status' => 'verified',
            'evidence_reference' => 'credit:38k-taxpayer-claim',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]];
        self::assertSame(200, $this->save($payload)->getStatusCode());

        $snapshot = $this->repository->snapshot(
            $this->supplierId,
            $this->employeeId,
            '2026-08-31',
        );
        self::assertIsArray($snapshot);

        $result = $this->monthlyIncomeTax(
            $snapshot['income_tax']['credit_claims'],
            TaxDeclarationStatus::NotSigned,
        );

        self::assertContains('tax-credit-requires-signed-declaration', $result->issues);
        self::assertSame(TaxCalculationStatus::ManualReview, $result->status);
        self::assertNull($result->advanceTax);
    }

    public function testHealthEvidenceDocumentRequiresSessionPermissionAndActiveTenantDocument(): void
    {
        if (!$this->db->hasColumn('payroll_person_health_coverage_history', 'health_evidence_document_id')) {
            $this->markTestSkipped('Migrace 1602 neproběhla.');
        }

        $documentId = $this->document($this->supplierId, str_repeat('d', 64));
        $payload = $this->completeEvidence();
        $payload['sections']['health_coverages'][0]['insurer_evidence_reference'] = 'health:insured-card';
        $payload['sections']['health_coverages'][0]['health_evidence_document_id'] = $documentId;

        $documentsOnly = new EffectiveRole(301, 'Bez zdravotních důkazů', 'staff', true, [
            'payroll.person.write' => 2,
        ]);
        $restricted = $this->saveAs($payload, $documentsOnly);
        self::assertSame(403, $restricted->getStatusCode());
        self::assertSame('forbidden', $this->json($restricted)['error']['code']);

        $healthWriter = new EffectiveRole(302, 'Zdravotní důkazy', 'staff', true, [
            'payroll.person.write' => 2,
            'payroll.health_evidence' => 2,
        ]);
        $stored = $this->saveAs($payload, $healthWriter);
        self::assertSame(200, $stored->getStatusCode(), (string) $stored->getBody());
        $coverage = $this->json($stored)['evidence']['sections']['health_coverages'][0];
        self::assertSame($documentId, (int) $coverage['health_evidence_document_id']);
        self::assertSame(str_repeat('d', 64), $coverage['health_evidence_document_sha256']);

        $foreignDocumentId = $this->document($this->otherSupplierId, str_repeat('e', 64));
        $foreignPayload = $this->completeEvidence();
        $foreignPayload['sections']['health_coverages'][0]['insurer_evidence_reference'] = 'health:insured-card';
        $foreignPayload['sections']['health_coverages'][0]['health_evidence_document_id'] = $foreignDocumentId;
        $foreign = $this->saveAs($foreignPayload, $healthWriter);
        self::assertSame(422, $foreign->getStatusCode());
        self::assertStringContainsString('aktivní dokument této firmy', $this->json($foreign)['error']['message']);

        $bearer = $this->saveAs($payload, $healthWriter, 'bearer');
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);
    }

    public function testHealthEvidenceDocumentMetadataRequiresReadPermission(): void
    {
        if (!$this->db->hasColumn('payroll_person_health_coverage_history', 'health_evidence_document_id')) {
            $this->markTestSkipped('Migrace 1602 neproběhla.');
        }

        $documentId = $this->document($this->supplierId, str_repeat('f', 64));
        $payload = $this->completeEvidence();
        $payload['sections']['health_coverages'][0]['insurer_evidence_reference'] = 'health:insured-card';
        $payload['sections']['health_coverages'][0]['health_evidence_document_id'] = $documentId;

        $healthWriter = new EffectiveRole(303, 'Zdravotní důkazy', 'staff', true, [
            'payroll.person.write' => 2,
            'payroll.health_evidence' => 2,
        ]);
        self::assertSame(200, $this->saveAs($payload, $healthWriter)->getStatusCode());

        $payrollOnly = new EffectiveRole(304, 'Pouze mzdy', 'staff', true, [
            'payroll' => 1,
        ]);
        $redacted = $this->json($this->showAs($payrollOnly));
        $redactedCoverage = $redacted['evidence']['sections']['health_coverages'][0];
        self::assertArrayNotHasKey('health_evidence_document_id', $redactedCoverage);
        self::assertArrayNotHasKey('health_evidence_document_sha256', $redactedCoverage);
        self::assertSame('health:insured-card', $redactedCoverage['insurer_evidence_reference']);

        $healthReader = new EffectiveRole(305, 'Čtení zdravotních důkazů', 'staff', true, [
            'payroll' => 1,
            'payroll.health_evidence' => 1,
        ]);
        $visible = $this->json($this->showAs($healthReader));
        $visibleCoverage = $visible['evidence']['sections']['health_coverages'][0];
        self::assertSame($documentId, (int) $visibleCoverage['health_evidence_document_id']);
        self::assertSame(str_repeat('f', 64), $visibleCoverage['health_evidence_document_sha256']);
    }

    public function testRedactedHealthEvidenceLinkSurvivesUnrelatedPayrollWrite(): void
    {
        if (!$this->db->hasColumn('payroll_person_health_coverage_history', 'health_evidence_document_id')) {
            $this->markTestSkipped('Migrace 1602 neproběhla.');
        }

        $documentId = $this->document($this->supplierId, str_repeat('a', 64));
        $payload = $this->completeEvidence();
        $payload['sections']['health_coverages'][0]['insurer_evidence_reference'] = 'health:insured-card';
        $payload['sections']['health_coverages'][0]['health_evidence_document_id'] = $documentId;

        $healthWriter = new EffectiveRole(306, 'Zápis zdravotních důkazů', 'staff', true, [
            'payroll.person.write' => 2,
            'payroll.health_evidence' => 2,
        ]);
        self::assertSame(200, $this->saveAs($payload, $healthWriter)->getStatusCode());

        $payrollReader = new EffectiveRole(307, 'Běžné mzdy', 'staff', true, [
            'payroll' => 1,
        ]);
        $redactedView = $this->json($this->showAs($payrollReader))['evidence'];
        self::assertArrayNotHasKey(
            'health_evidence_document_id',
            $redactedView['sections']['health_coverages'][0],
        );

        $unrelatedUpdate = $this->payloadFrom($redactedView);
        $unrelatedUpdate['sections']['tax_declarations'][0]['evidence_reference']
            = 'document:tax-declaration-corrected';
        $payrollWriter = new EffectiveRole(308, 'Zápis běžných mezd', 'staff', true, [
            'payroll.person.write' => 2,
        ]);
        $saved = $this->saveAs($unrelatedUpdate, $payrollWriter);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $savedCoverage = $this->json($saved)['evidence']['sections']['health_coverages'][0];
        self::assertArrayNotHasKey('health_evidence_document_id', $savedCoverage);
        self::assertArrayNotHasKey('health_evidence_document_sha256', $savedCoverage);

        $healthReader = new EffectiveRole(309, 'Čtení zdravotních důkazů', 'staff', true, [
            'payroll' => 1,
            'payroll.health_evidence' => 1,
        ]);
        $visible = $this->json($this->showAs($healthReader))['evidence'];
        $visibleCoverage = $visible['sections']['health_coverages'][0];
        self::assertSame($documentId, (int) $visibleCoverage['health_evidence_document_id']);
        self::assertSame(str_repeat('a', 64), $visibleCoverage['health_evidence_document_sha256']);

        /*
         * Špatně připojený sken JDE vyměnit.
         *
         * Doklad býval „po připojení neměnný" a řádek evidence se u zmrazeného
         * období nedá ani smazat — kdo připojil špatný sken, neměl cestu ven.
         * Ověřování zůstává: nový otisk čte server z DMS, klient ho neposílá.
         */
        $replacementSha = str_repeat('b', 64);
        $changedLink = $this->payloadFrom($visible);
        $changedLink['sections']['health_coverages'][0]['health_evidence_document_id']
            = $this->document($this->supplierId, $replacementSha);
        $replaced = $this->saveAs($changedLink, $healthWriter);
        self::assertSame(200, $replaced->getStatusCode(), (string) $replaced->getBody());
        $afterReplacement = $this->json($this->showAs($healthReader))['evidence'];
        self::assertSame(
            $replacementSha,
            $afterReplacement['sections']['health_coverages'][0]['health_evidence_document_sha256'],
        );
    }

    public function testNewVersionInTimeKeepsTheOlderOneUntouched(): void
    {
        $stored = $this->json($this->save($this->completeEvidence()))['evidence'];
        $declaration = $stored['sections']['tax_declarations'][0];

        $payload = $this->completeEvidence();
        $payload['sections']['tax_declarations'] = [
            [
                'id' => $declaration['id'],
                'row_version' => $declaration['row_version'],
                'status' => 'signed',
                'evidence_reference' => 'document:tax-declaration-2026',
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-06-30',
            ],
            [
                'status' => 'not-signed',
                'evidence_reference' => 'document:tax-declaration-withdrawn',
                'effective_from' => '2026-07-01',
                'effective_to' => null,
            ],
        ];
        $response = $this->save($payload);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $rows = $this->json($response)['evidence']['sections']['tax_declarations'];
        self::assertCount(2, $rows);
        self::assertSame('2026-06-30', $rows[0]['effective_to']);
        self::assertSame('signed', $rows[0]['status'], 'Historie se nesmí přepsat.');
        self::assertSame('2026-07-01', $rows[1]['effective_from']);
        self::assertSame('not-signed', $rows[1]['status']);

        $before = $this->repository->snapshot(
            $this->supplierId,
            $this->employeeId,
            '2026-05-31',
        );
        self::assertIsArray($before);
        self::assertSame('signed', $before['income_tax']['declaration']['status']);

        $after = $this->repository->snapshot(
            $this->supplierId,
            $this->employeeId,
            '2026-08-31',
        );
        self::assertIsArray($after);
        self::assertSame('not-signed', $after['income_tax']['declaration']['status']);
    }

    public function testGapInTheTimelineIsRejected(): void
    {
        $payload = $this->completeEvidence();
        $payload['sections']['tax_declarations'] = [
            [
                'status' => 'signed',
                'evidence_reference' => 'document:tax-declaration-2026',
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-05-31',
            ],
            [
                'status' => 'signed',
                'evidence_reference' => 'document:tax-declaration-later',
                'effective_from' => '2026-08-01',
                'effective_to' => null,
            ],
        ];

        $response = $this->save($payload);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'navazovat',
            (string) $this->json($response)['error']['message'],
        );
        self::assertSame(0, $this->countRows('payroll_person_tax_declarations'));
    }

    public function testOverlapInTheTimelineIsRejected(): void
    {
        $payload = $this->completeEvidence();
        $payload['sections']['tax_declarations'] = [
            [
                'status' => 'signed',
                'evidence_reference' => 'document:tax-declaration-2026',
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
            ],
            [
                'status' => 'not-signed',
                'evidence_reference' => 'document:tax-declaration-later',
                'effective_from' => '2026-06-01',
                'effective_to' => null,
            ],
        ];

        $response = $this->save($payload);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->countRows('payroll_person_tax_declarations'));
    }

    public function testMidMonthStartIsRejectedBecauseTheReaderWorksPerMonth(): void
    {
        $payload = $this->completeEvidence();
        $payload['sections']['tax_declarations'][0]['effective_from'] = '2026-01-15';

        $response = $this->save($payload);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'celých měsících',
            (string) $this->json($response)['error']['message'],
        );
    }

    public function testLegalFactWithoutEvidenceReferenceIsAccepted(): void
    {
        $payload = $this->completeEvidence();
        $payload['sections']['tax_declarations'][0]['evidence_reference'] = null;

        $response = $this->save($payload);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull(
            $this->json($response)['evidence']['sections']['tax_declarations'][0]
                ['evidence_reference'],
        );
        self::assertSame(1, $this->countRows('payroll_person_tax_declarations'));
    }

    public function testHumanExplanationCannotSneakIntoTheCanonicalReference(): void
    {
        $payload = $this->completeEvidence();
        $payload['sections']['tax_declarations'][0]['evidence_reference']
            = 'papír ve složce u paní účetní';

        $response = $this->save($payload);

        self::assertSame(422, $response->getStatusCode());
        $message = (string) $this->json($response)['error']['message'];
        // Hláška musí jmenovat POLE a povolený tvar, ne jen odbýt uživatele
        // slovem „kanonická reference" — z toho se nepozná, co se po něm chce.
        self::assertStringContainsString('evidence_reference', $message);
        self::assertStringContainsString('bez diakritiky', $message);
        self::assertStringContainsString('např.', $message);
    }

    public function testUnverifiedVariantIsAcceptedAndStaysVisibleAsABlocker(): void
    {
        $payload = $this->completeEvidence();
        $payload['sections']['tax_declarations'][0]['status'] = 'unverified';
        $payload['sections']['tax_declarations'][0]['evidence_reference'] = null;
        $payload['sections']['tax_declarations'][0]['evidence_note']
            = 'Zaměstnanec prohlášení zatím nepodepsal.';

        $response = $this->save($payload);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $evidence = $this->json($response)['evidence'];
        self::assertSame('unverified', $evidence['sections']['tax_declarations'][0]['status']);
        self::assertContains('tax_declaration_evidence_unverified', $evidence['blockers']);
    }

    public function testStaleRowVersionReturnsConflict(): void
    {
        $stored = $this->json($this->save($this->completeEvidence()))['evidence'];
        $declaration = $stored['sections']['tax_declarations'][0];

        $payload = $this->completeEvidence();
        $payload['sections']['tax_declarations'] = [[
            'id' => $declaration['id'],
            'row_version' => $declaration['row_version'] + 41,
            'status' => 'not-signed',
            'evidence_reference' => 'document:tax-declaration-withdrawn',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]];

        $response = $this->save($payload);

        self::assertSame(409, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame('row_version_conflict', $error['code']);
        self::assertSame('tax_declarations', $error['collection']);
    }

    public function testFrozenRowIsNotRewrittenButSupersededFromTheNextMonth(): void
    {
        $this->save($this->completeEvidence());
        $stored = $this->json($this->show())['evidence'];
        $declaration = $stored['sections']['tax_declarations'][0];

        $this->approveRun('2026-04-01');

        $payload = $this->payloadFrom($stored);
        $payload['sections']['tax_declarations'] = [[
            'id' => $declaration['id'],
            'row_version' => $declaration['row_version'],
            'status' => 'not-signed',
            'evidence_reference' => 'document:tax-declaration-withdrawn',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]];
        $response = $this->save($payload);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $rows = $this->json($response)['evidence']['sections']['tax_declarations'];
        self::assertCount(2, $rows);
        self::assertSame($declaration['id'], $rows[0]['id']);
        self::assertSame('signed', $rows[0]['status'], 'Zmrazená historie se nepřepisuje.');
        self::assertSame('2026-04-30', $rows[0]['effective_to']);
        self::assertSame('2026-05-01', $rows[1]['effective_from']);
        self::assertSame('not-signed', $rows[1]['status']);
    }

    public function testFrozenRowCannotBeDeleted(): void
    {
        $stored = $this->json($this->save($this->completeEvidence()))['evidence'];
        $this->approveRun('2026-04-01');

        $payload = $this->payloadFrom($stored);
        $payload['sections']['tax_declarations'] = [];

        $response = $this->save($payload);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'uzavřeného schválenou mzdou',
            (string) $this->json($response)['error']['message'],
        );
        self::assertSame(1, $this->countRows('payroll_person_tax_declarations'));
    }

    public function testTenantIsolation(): void
    {
        $this->save($this->completeEvidence());

        $foreignRead = $this->show($this->otherSupplierId);
        self::assertSame(404, $foreignRead->getStatusCode());

        $foreignWrite = $this->save($this->completeEvidence(), $this->otherSupplierId);
        self::assertSame(404, $foreignWrite->getStatusCode());

        $ownEmployee = $this->json($this->action->show(
            $this->request(
                'GET',
                $this->otherSupplierId,
                "/api/payroll/people/{$this->otherEmployeeId}/statutory-evidence",
            ),
            new Response(),
            ['id' => (string) $this->otherEmployeeId],
        ));
        self::assertSame([], $ownEmployee['evidence']['sections']['tax_declarations']);
    }

    public function testWriteRequiresPersonWritePermission(): void
    {
        $response = $this->action->save(
            $this->request(
                'PUT',
                $this->supplierId,
                "/api/payroll/people/{$this->employeeId}/statutory-evidence",
                $this->completeEvidence(),
                'viewer',
            ),
            new Response(),
            ['id' => (string) $this->employeeId],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countRows('payroll_person_tax_declarations'));
    }

    public function testBearerTokenCannotReachTheEvidence(): void
    {
        $response = $this->action->show(
            $this->request(
                'GET',
                $this->supplierId,
                "/api/payroll/people/{$this->employeeId}/statutory-evidence",
                null,
                'accountant',
                'bearer',
            ),
            new Response(),
            ['id' => (string) $this->employeeId],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('session_required', $this->json($response)['error']['code']);
    }

    // --- pomocníci ---------------------------------------------------------

    /**
     * Měsíční daň z jednoho pracovního poměru se 40 000 Kč hrubého; slevy
     * se berou přímo z řádků snímku, ať test nepočítá s jinými daty, než
     * jaká zapsalo API.
     *
     * @param list<array<string,mixed>> $creditClaims řádky
     *     `income_tax.credit_claims` ze snímku mzdového běhu
     */
    private function monthlyIncomeTax(
        array $creditClaims,
        TaxDeclarationStatus $declaration = TaxDeclarationStatus::Signed,
    ): MonthlyEmploymentIncomeTaxResult {
        $claims = [];
        foreach ($creditClaims as $row) {
            $claims[] = new TaxCreditClaim(
                TaxCreditKind::from((string) $row['credit_kind']),
                (string) $row['effective_from'],
                $row['effective_to'] === null ? null : (string) $row['effective_to'],
                TaxEvidenceStatus::from((string) $row['evidence_status']),
                $row['evidence_reference'] === null
                    ? null
                    : (string) $row['evidence_reference'],
            );
        }

        $calculator = new MonthlyEmploymentIncomeTaxCalculator(
            new PayrollRulesetProvider([
                CzechPayrollRulesets2026::provider()
                    ->forDate(PayrollRulesetDomain::IncomeTax, '2026-08-31'),
            ]),
        );

        return $calculator->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-08-31',
            employeeReference: "employee:{$this->employeeId}",
            relationships: [new EmploymentRelationshipTaxInput(
                'employment:synthetic',
                "supplier:{$this->supplierId}",
                EmploymentRelationshipKind::Employment,
                [new IncomeTaxComponent('synthetic-income', 4_000_000)],
            )],
            declarations: [new TaxDeclarationEvidence(
                $declaration,
                '2026-01-01',
                null,
                $declaration === TaxDeclarationStatus::Signed
                    ? 'document:tax-declaration-2026'
                    : 'declaration:38k-not-signed',
            )],
            residence: new TaxResidenceEvidence(
                TaxResidence::CzechResident,
                '2026-01-01',
                null,
                'document:tax-residence-2026',
            ),
            creditClaims: $claims,
        ));
    }

    /** @return array<string,mixed> */
    private function completeEvidence(): array
    {
        return [
            'effective_on' => '2026-08-31',
            'sections' => [
                'tax_declarations' => [[
                    'status' => 'signed',
                    'evidence_reference' => 'document:tax-declaration-2026',
                    'evidence_note' => 'Podepsáno na papíře, uloženo ve složce zaměstnance',
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ]],
                'tax_residences' => [[
                    'residence' => 'czech-resident',
                    'country_code' => 'CZ',
                    'evidence_reference' => 'document:tax-residence-2026',
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ]],
                // Slevy na dani jsou nepovinné — výchozí evidence je bez nich,
                // ať se na nich neveze každý jiný test.
                'tax_credit_claims' => [],
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
                    'insurer_evidence_reference' => null,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ]],
                'health_month_evidence' => [[
                    'period_start' => '2026-08-01',
                    'top_up_responsibility' => 'employer_obstacle_verified',
                    'top_up_responsibility_evidence_reference' => 'document:employer-obstacle',
                    'selected_top_up_employer_reference' => null,
                    'selected_top_up_employer_evidence_reference' => null,
                ]],
            ],
        ];
    }

    /**
     * Vrátí uložený stav zpět jako tělo požadavku.
     *
     * Zápis popisuje CÍLOVÝ stav, takže kolekce vynechaná z těla znamená smazat.
     * Test, který mění jednu řadu, musí ostatní poslat beze změny — jinak by
     * neověřoval to, co si myslí.
     *
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    private function payloadFrom(array $evidence): array
    {
        $sections = [];
        foreach ($evidence['sections'] as $key => $rows) {
            $sections[$key] = array_map(
                static fn (array $row): array => $row,
                $rows,
            );
        }

        return ['effective_on' => '2026-08-31', 'sections' => $sections];
    }

    private function show(?int $supplierId = null): Response
    {
        return $this->action->show(
            $this->request(
                'GET',
                $supplierId ?? $this->supplierId,
                "/api/payroll/people/{$this->employeeId}/statutory-evidence"
                . '?effective_on=2026-08-31',
            )->withQueryParams(['effective_on' => '2026-08-31']),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
    }

    private function showAs(EffectiveRole $role): Response
    {
        return $this->action->show(
            $this->request(
                'GET',
                $this->supplierId,
                "/api/payroll/people/{$this->employeeId}/statutory-evidence"
                . '?effective_on=2026-08-31',
            )->withQueryParams(['effective_on' => '2026-08-31'])
                ->withAttribute('auth.effective_role', $role),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
    }

    /** @param array<string,mixed> $payload */
    private function save(array $payload, ?int $supplierId = null): Response
    {
        return $this->action->save(
            $this->request(
                'PUT',
                $supplierId ?? $this->supplierId,
                "/api/payroll/people/{$this->employeeId}/statutory-evidence",
                $payload,
            ),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
    }

    /** @param array<string,mixed> $payload */
    private function saveAs(
        array $payload,
        EffectiveRole $role,
        string $authMethod = 'session',
    ): Response {
        return $this->action->save(
            $this->request(
                'PUT',
                $this->supplierId,
                "/api/payroll/people/{$this->employeeId}/statutory-evidence",
                $payload,
                'accountant',
                $authMethod,
            )->withAttribute('auth.effective_role', $role),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
    }

    private function countRows(string $table): int
    {
        $statement = $this->db->pdo()->prepare(sprintf(
            'SELECT COUNT(*) FROM %s WHERE supplier_id = ? AND employee_id = ?',
            $table,
        ));
        $statement->execute([$this->supplierId, $this->employeeId]);

        return (int) $statement->fetchColumn();
    }

    /** @param array<string,mixed>|null $body */
    private function request(
        string $method,
        int $supplierId,
        string $path,
        ?array $body = null,
        string $role = 'accountant',
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);

        return $body === null ? $request : $request->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function createEmployee(int $supplierId): int
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba", "employee", "hpp", 1, 1, 0, 40000, 0, 1)'
        );
        $statement->execute([$supplierId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function document(int $supplierId, string $sha256): int
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO documents
                (supplier_id, title, original_name, filename, sha256, mime_type,
                 size_bytes, doc_type, source, uploaded_by, scope)
             VALUES (?, "Syntetický zdravotní důkaz", "health-evidence.pdf", ?, ?,
                     "application/pdf", 1, "pdf", "manual", ?, "company")',
        );
        $statement->execute([
            $supplierId,
            $sha256 . '.pdf',
            $sha256,
            $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function approveRun(string $periodStart): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status)
             VALUES (?, ?, ?, 'approved')"
        )->execute([
            $this->supplierId,
            $periodStart,
            (new \DateTimeImmutable($periodStart))->modify('+40 days')->format('Y-m-d'),
        ]);
        $runId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 idempotency_key_hash)
             VALUES (?, ?, 1, 'approved', 'test-1', ?, '{}', ?, ?)"
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            random_bytes(32),
        ]);
    }
}
