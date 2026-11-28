<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\NonprofitTaxDraftRepository;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentConfirmationService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentDraftService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentPreviewService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentReadService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6DraftContext;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6LegalProfileMatch;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6PreviewCache;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoDataProvider;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoReadiness;
use MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * #402 krok 2, část A: koncept J6 převezme potvrzený právní profil DPPO a připne jeho revizi;
 * náhled a potvrzení stránkované cesty hlídají shodu postavení (matice A1–A12, A14, A16).
 */
final class J6LegalProfileAdoptionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private \DI\Container $container;
    private Connection $db;
    private PDO $pdo;
    private int $supplier;
    private int $period;
    private int $actor;
    private array $previewTokens = [];

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertSame((string) getenv('MYINVOICE_DB_NAME'), (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $this->actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$this->supplier]);
        $this->period = $this->period(2026);
    }

    protected function tearDown(): void
    {
        foreach ($this->previewTokens as $token) {
            $cache = $this->container->get(J6PreviewCache::class);
            $cache->removeOwnedDirectory($cache->previewPath($token, true));
        }
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testA1OpenWithoutPostureAdoptsConfirmedProfileAndPinsItsRevision(): void
    {
        $legal = $this->legal('narrow_vpp', 'association', 0);
        $draft = $this->drafts()->openCurrent($this->supplier, $this->period, null, null, $this->actor);

        self::assertEquals(['kind' => 'narrow_vpp', 'evidence' => 'Synthetic statutes'], $draft['header']['profile']);
        self::assertEquals(['id' => $legal['id'], 'revision_no' => 1, 'record_hash' => $legal['record_hash']], $draft['header']['legal_profile']);
        self::assertSame('pinned_current', $this->state($draft)['state']);
        // Stejné postavení v těle projde a převezme podklad z profilu.
        $reset = $this->drafts()->resetCurrent($this->supplier, $this->period, $draft['id'], $draft['revision_no'],
            ['kind' => 'narrow_vpp', 'evidence' => 'Jiný podklad v těle'], null, $this->actor);
        self::assertSame('Synthetic statutes', $reset['header']['profile']['evidence']);
    }

    public function testA2DifferentPostureInBodyIsRejectedWithoutWriting(): void
    {
        $this->legal('narrow_vpp', 'association', 0);
        $this->expectFailure('nonprofit_tax_legal_profile_mismatch', 422, fn () => $this->drafts()->openCurrent($this->supplier, $this->period,
            ['kind' => 'broad_vpp', 'evidence' => 'Syntetické'], null, $this->actor));
        self::assertSame(0, $this->draftCount());
    }

    public function testA3WithoutProfilePostureStaysManualAndConfirms(): void
    {
        $draft = $this->open(['kind' => 'non_vpp', 'evidence' => 'Syntetické rozhodnutí']);
        self::assertArrayNotHasKey('legal_profile', $draft['header']);
        self::assertSame('none', $this->state($draft)['state']);
        $this->preview($draft);
        self::assertSame('confirmed', $this->confirm($draft, 'a3')->status);
        $this->expectFailure('nonprofit_tax_facts_incomplete', 422, fn () => $this->drafts()->openCurrent($this->supplier, $this->period(2025), null, null, $this->actor));
    }

    public function testA4OlderDraftWithoutPinAndSamePosturePreviewsAndConfirms(): void
    {
        $draft = $this->open(['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy']);
        $this->legal('narrow_vpp', 'association', 0);
        self::assertSame('manual', $this->state($draft)['state']);
        $this->preview($draft);
        self::assertSame('confirmed', $this->confirm($draft, 'a4')->status);
    }

    public function testA5OlderDraftWithDifferentPostureConflictsInPreviewAndConfirmation(): void
    {
        $draft = $this->open(['kind' => 'broad_vpp', 'evidence' => 'Syntetické stanovy']);
        $this->legal('narrow_vpp', 'association', 0);
        self::assertSame('conflict', $this->state($draft)['state']);
        $this->expectFailure('nonprofit_tax_legal_profile_conflict', 409, fn () => $this->preview($draft));
        $this->expectFailure('nonprofit_tax_legal_profile_conflict', 409, fn () => $this->confirm($draft, 'a5'));
        self::assertNull($this->container->get(J6AssessmentReadService::class)->historyHeader($this->supplier, $this->period)['assessment_id']);
    }

    public function testA6NewRevisionWithSameKindKeepsAssessmentValidAndPinKeepsDecisions(): void
    {
        $this->legal('narrow_vpp', 'association', 0);
        $draft = $this->drafts()->openCurrent($this->supplier, $this->period, null, null, $this->actor);
        $completion = $this->confirm($draft, 'a6');
        $summary = $this->container->get(J6AssessmentReadService::class)->summaryById($this->supplier, $completion->assessmentId);
        self::assertSame(1, $summary['legal_profile']['revision_no']);
        self::assertSame(1, $this->container->get(J6AssessmentReadService::class)->documentSummary($this->supplier, $this->period, $completion->assessmentId)['legal_profile']['revision_no']);

        $second = $this->legal('narrow_vpp', 'association', 1, 'Synthetic amended statutes');
        self::assertSame('pinned_same_kind_outdated', $this->state($draft)['state']);
        // Potvrzené posouzení zůstává platné pro DPPO, připravenost ukáže obě revize.
        $steps = $this->readiness();
        self::assertSame(['status' => 'ok', 'pinned_profile_revision' => 1, 'current_profile_revision' => 2],
            array_intersect_key($steps['profile_match'], array_flip(['status', 'pinned_profile_revision', 'current_profile_revision'])));
        $this->preview($draft);

        // A14: připnutí mění jen hlavičku; rozhodnutí (včetně volby daru) i činnosti zůstanou beze změny.
        $patched = $this->drafts()->patchDecisions($this->supplier, $this->period, $draft['id'], $draft['revision_no'],
            [['source_id' => 'line:999999', 'payload' => ['title' => 'gift', 'facts' => ['claim_exemption' => true]]]], [], $this->actor);
        $patched = $this->drafts()->patchActivities($this->supplier, $this->period, $draft['id'], $patched['revision_no'],
            [['activity_kind_id' => 'synthetic-activity', 'payload' => ['kind' => 'nonbusiness']]], [], $this->actor);
        $repository = new NonprofitTaxDraftRepository($this->db);
        $before = [$repository->page($this->supplier, $draft['id'], 'decision'), $repository->page($this->supplier, $draft['id'], 'activity')];
        $pinned = $this->drafts()->pinLegalProfile($this->supplier, $this->period, $draft['id'], $patched['revision_no'], $this->actor);
        self::assertSame($patched['revision_no'] + 1, $pinned['revision_no']);
        self::assertEquals(['id' => $second['id'], 'revision_no' => 2, 'record_hash' => $second['record_hash']], $pinned['header']['legal_profile']);
        self::assertSame('Synthetic amended statutes', $pinned['header']['profile']['evidence']);
        self::assertSame($draft['source_input_hash'], $pinned['source_input_hash']);
        self::assertSame($before, [$repository->page($this->supplier, $draft['id'], 'decision'), $repository->page($this->supplier, $draft['id'], 'activity')]);
        self::assertSame('pinned_current', $this->state($pinned)['state']);
    }

    public function testA7KindChangeAfterConfirmationBlocksJ6AndDppoUntilReset(): void
    {
        $this->legal('narrow_vpp', 'association', 0);
        $draft = $this->drafts()->openCurrent($this->supplier, $this->period, null, null, $this->actor);
        $this->confirm($draft, 'a7');
        $this->legal('broad_vpp', 'public_benefit_company', 1);

        self::assertSame('conflict', $this->state($draft)['state']);
        $this->expectFailure('nonprofit_tax_legal_profile_conflict', 409, fn () => $this->preview($draft));
        $this->expectFailure('nonprofit_tax_legal_profile_conflict', 409,
            fn () => $this->drafts()->pinLegalProfile($this->supplier, $this->period, $draft['id'], $draft['revision_no'], $this->actor));
        try {
            $this->container->get(NonprofitDppoDataProvider::class)->gather($this->supplier, 2026);
            self::fail('DPPO must reject an assessment with a different posture.');
        } catch (TaxReturnException $error) {
            self::assertSame('nonprofit_dppo_profile_conflict', $error->errorCode);
        }
        $steps = $this->readiness();
        self::assertSame(['status' => 'failed', 'pinned_profile_revision' => 1, 'current_profile_revision' => 2],
            array_intersect_key($steps['profile_match'], array_flip(['status', 'pinned_profile_revision', 'current_profile_revision'])));

        $reset = $this->drafts()->resetCurrent($this->supplier, $this->period, $draft['id'], $draft['revision_no'], null, null, $this->actor);
        self::assertSame('broad_vpp', $reset['header']['profile']['kind']);
        self::assertSame(2, $reset['header']['legal_profile']['revision_no']);
        $this->preview($reset);
    }

    public function testA8ProfileChangedAfterPreviewBlocksConfirmation(): void
    {
        $this->legal('narrow_vpp', 'association', 0);
        $draft = $this->drafts()->openCurrent($this->supplier, $this->period, null, null, $this->actor);
        $this->preview($draft);
        $this->legal('broad_vpp', 'public_benefit_company', 1);
        $this->expectFailure('nonprofit_tax_legal_profile_conflict', 409, fn () => $this->confirm($draft, 'a8'));
    }

    public function testA9ManualPeriodAdoptsDocumentedIdentityAndOldManualDraftIsNotCompared(): void
    {
        $period = $this->period(2023);
        $method = ['mode' => 'documented_manual', 'valid_from' => '2023-01-01', 'valid_to' => '2023-12-31',
            'legal_basis' => 'Syntetický právní rozbor', 'evidence' => 'Syntetický podklad metody'];
        // Ruční koncept bez doložené identity vzniklý před profilem se s profilem neporovnává (jako dosud).
        $old = $this->drafts()->openCurrent($this->supplier, $period, ['kind' => 'documented', 'evidence' => 'Syntetické'], $method, $this->actor);
        $this->legal('broad_vpp', 'public_benefit_company', 0, 'Synthetic statutes', $period);
        self::assertSame('manual', J6LegalProfileMatch::state($old['header'], $this->match()->context($this->supplier, $period))['state']);
        $this->preview($old, $period);

        $reset = $this->drafts()->resetCurrent($this->supplier, $period, $old['id'], $old['revision_no'], null, $method, $this->actor);
        self::assertEquals(['kind' => 'documented', 'evidence' => 'Synthetic statutes', 'entity_kind' => 'broad_vpp'], $reset['header']['profile']);
        self::assertSame(1, $reset['header']['legal_profile']['revision_no']);
        $this->preview($reset, $period);
        $this->expectFailure('nonprofit_tax_legal_profile_mismatch', 422, fn () => $this->drafts()->resetCurrent($this->supplier, $period,
            $reset['id'], $reset['revision_no'], ['kind' => 'documented', 'evidence' => 'x', 'entity_kind' => 'narrow_vpp'], $method, $this->actor));
    }

    public function testA10SvjModuleRejectsNonSvjPostureOnPagedPath(): void
    {
        (new PropertyProfileRepository($this->db))->save($this->supplier, ['expected_revision' => 0, 'effective_from' => '2026-01-01T00:00:00Z',
            'effective_to' => null, 'enabled' => true, 'reason' => 'Synthetic SVJ module', 'title_ref' => 'Synthetic legal title',
            'idempotency_key' => 'svj-module-' . $this->supplier], $this->actor);
        $draft = $this->open(['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy']);
        self::assertSame('conflict', $this->state($draft)['state']);
        $this->expectFailure('nonprofit_tax_legal_profile_conflict', 409, fn () => $this->preview($draft));
        $this->expectFailure('nonprofit_tax_legal_profile_conflict', 409, fn () => $this->confirm($draft, 'a10'));
    }

    public function testReadinessKeepsStepsWhenProfileCannotBeProven(): void
    {
        $this->legal('narrow_vpp', 'association', 0);
        // Modul SVJ zapnutý až po potvrzení profilu jiného postavení: profil nejde doložit.
        (new PropertyProfileRepository($this->db))->save($this->supplier, ['expected_revision' => 0, 'effective_from' => '2026-01-01T00:00:00Z',
            'effective_to' => null, 'enabled' => true, 'reason' => 'Synthetic SVJ module', 'title_ref' => 'Synthetic legal title',
            'idempotency_key' => 'svj-module-readiness-' . $this->supplier], $this->actor);
        $steps = $this->readiness();
        self::assertSame('failed', $steps['legal_profile']['status']);
        self::assertArrayHasKey('current_profile_revision', $steps['profile_match']);
        self::assertNull($steps['profile_match']['current_profile_revision']);
        self::assertNull($steps['profile_match']['pinned_profile_revision']);
    }

    public function testManualMethodWithNonDocumentedKindInBodyIsMismatch(): void
    {
        $period = $this->period(2023);
        $this->legal('narrow_vpp', 'association', 0, 'Synthetic statutes', $period);
        $method = ['mode' => 'documented_manual', 'valid_from' => '2023-01-01', 'valid_to' => '2023-12-31',
            'legal_basis' => 'Syntetický právní rozbor', 'evidence' => 'Syntetický podklad metody'];
        $this->expectFailure('nonprofit_tax_legal_profile_mismatch', 422, fn () => $this->drafts()->openCurrent($this->supplier, $period,
            ['kind' => 'broad_vpp', 'evidence' => 'Syntetické'], $method, $this->actor));
        self::assertSame(0, $this->draftCount());
    }

    public function testWithdrawalRevisionCarriesNoPin(): void
    {
        $this->legal('narrow_vpp', 'association', 0);
        $draft = $this->drafts()->openCurrent($this->supplier, $this->period, null, null, $this->actor);
        $this->preview($draft);
        $confirmed = $this->confirm($draft, 'withdraw');
        // Odvolání odkazuje na manifest rozhodnutí předchozí revize; připnutí nese jen potvrzená revize.
        $withdrawn = $this->container->get(\MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentWithdrawalService::class)
            ->withdraw($this->supplier, $this->period, $confirmed->assessmentId, 'Syntetické odvolání', 'synthetic-withdraw-' . $this->supplier, $this->actor);
        $read = $this->container->get(J6AssessmentReadService::class);
        self::assertSame(1, $read->summaryById($this->supplier, $confirmed->assessmentId)['legal_profile']['revision_no']);
        self::assertSame('withdrawn', $read->summaryById($this->supplier, $withdrawn->assessmentId)['status']);
        self::assertNull($read->summaryById($this->supplier, $withdrawn->assessmentId)['legal_profile']);
        self::assertNull($read->history($this->supplier, $this->period)['rows'][1]['legal_profile']);
        // Dokument odvolání popisuje odvolané rozhodnutí: jeho postavení i připnutou revizi.
        $document = $read->documentSummary($this->supplier, $this->period, $withdrawn->assessmentId);
        self::assertSame([null, 1], [$document['assessment']['legal_profile'], $document['legal_profile']['revision_no']]);
    }

    /** Limit (beze změny): DPPO 504 ruční posouzení nepřijímá ani s převzatou identitou (NonprofitDppoDataProvider). */
    public function testDppoRejectsManualAssessmentEvenWithAdoptedIdentity(): void
    {
        $period = $this->period(2023);
        $this->legal('narrow_vpp', 'association', 0, 'Synthetic statutes', $period);
        $method = ['mode' => 'documented_manual', 'valid_from' => '2023-01-01', 'valid_to' => '2023-12-31',
            'legal_basis' => 'Syntetický právní rozbor', 'evidence' => 'Syntetický podklad metody'];
        $draft = $this->drafts()->openCurrent($this->supplier, $period, null, $method, $this->actor);
        self::assertSame(['documented', 'narrow_vpp'], [$draft['header']['profile']['kind'], $draft['header']['profile']['entity_kind']]);
        self::assertSame('pinned_current', $this->state($draft)['state']);
        $this->confirm($draft, 'manual-dppo');
        try {
            $this->container->get(NonprofitDppoDataProvider::class)->gather($this->supplier, 2023);
            self::fail('DPPO 504 must not accept a documented manual assessment.');
        } catch (TaxReturnException $error) {
            self::assertSame('nonprofit_dppo_profile_conflict', $error->errorCode);
        }
    }

    /** Limit (beze změny): pojistka uzávěrky stránkované cesty shodu postavení s profilem nekontroluje, blokuje až DPPO. */
    public function testClosingGuardDoesNotCheckPostureAgainstChangedProfile(): void
    {
        $this->legal('narrow_vpp', 'association', 0);
        $draft = $this->drafts()->openCurrent($this->supplier, $this->period, null, null, $this->actor);
        $completion = $this->confirm($draft, 'closing-guard');
        $this->legal('broad_vpp', 'public_benefit_company', 1);
        self::assertSame('conflict', $this->state($draft)['state']);
        $check = $this->container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentGuard::class)->closingCheck($this->supplier, $this->period);
        self::assertSame([true, [], $completion->assessmentId], [$check['complete'], $check['findings'], $check['assessment_id']]);
    }

    public function testA12NewProfileRevisionInvalidatesCachedPreview(): void
    {
        $this->legal('narrow_vpp', 'association', 0);
        $draft = $this->drafts()->openCurrent($this->supplier, $this->period, null, null, $this->actor);
        $token = $this->preview($draft)->previewToken;
        $context = $this->context($draft);
        self::assertSame([], $this->container->get(J6AssessmentPreviewService::class)->page($token, $context, 'result', 0)['rows']);
        $this->legal('narrow_vpp', 'association', 1);
        $this->expectException(\UnexpectedValueException::class);
        $this->container->get(J6AssessmentPreviewService::class)->page($token, $context, 'result', 0);
    }

    public function testA16ProfileToJ6ToConfirmationToDppoComputation(): void
    {
        $this->legal('narrow_vpp', 'association', 0);
        $draft = $this->drafts()->openCurrent($this->supplier, $this->period, null, null, $this->actor);
        $this->preview($draft);
        $this->confirm($draft, 'a16');
        $computed = $this->container->get(NonprofitDppoDataProvider::class)->gather($this->supplier, 2026);
        self::assertSame('narrow_vpp', $computed['nonprofit']['legal_profile']['profile']['kind']);
        self::assertTrue($this->container->get(NonprofitDppoReadiness::class)->check($this->supplier,
            (new AccountingPeriodRepository($this->db))->findById($this->supplier, $this->period))['ready']);
    }

    public function testConflictBlocksPreviewAlsoInClosedPeriod(): void
    {
        $draft = $this->open(['kind' => 'broad_vpp', 'evidence' => 'Syntetické stanovy']);
        $this->legal('narrow_vpp', 'association', 0);
        $this->pdo->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $this->period]);
        $this->expectFailure('nonprofit_tax_legal_profile_conflict', 409, fn () => $this->preview($draft));
        // Přechod resetem vyžaduje otevřené období (popis PR: uzavřené období nejdřív znovu otevřít).
        $this->expectFailure('nonprofit_tax_period_locked', 409, fn () => $this->drafts()->resetCurrent($this->supplier, $this->period,
            $draft['id'], $draft['revision_no'], null, null, $this->actor));
    }

    public function testA8bConfirmationAfterNewRevisionOfSamePosturePinsTheDraftRevision(): void
    {
        $first = $this->legal('narrow_vpp', 'association', 0);
        $draft = $this->drafts()->openCurrent($this->supplier, $this->period, null, null, $this->actor);
        $this->preview($draft);
        $this->legal('narrow_vpp', 'association', 1, 'Synthetic amended statutes');
        $completion = $this->confirm($draft, 'a8b');
        $summary = $this->container->get(J6AssessmentReadService::class)->summaryById($this->supplier, $completion->assessmentId);
        self::assertEquals(['id' => $first['id'], 'revision_no' => 1, 'record_hash' => $first['record_hash']], $summary['legal_profile']);
        self::assertSame('pinned_same_kind_outdated', $this->state($draft)['state']);
        self::assertTrue($this->state($draft)['pinnable']);
    }

    public function testPinningTheAlreadyCurrentRevisionKeepsTheDraftRevision(): void
    {
        $this->legal('narrow_vpp', 'association', 0);
        $draft = $this->drafts()->openCurrent($this->supplier, $this->period, null, null, $this->actor);
        self::assertFalse($this->state($draft)['pinnable']);
        $again = $this->drafts()->pinLegalProfile($this->supplier, $this->period, $draft['id'], $draft['revision_no'], $this->actor);
        self::assertSame($draft['revision_no'], $again['revision_no']);
        $this->expectFailure('nonprofit_tax_draft_changed', 409,
            fn () => $this->drafts()->pinLegalProfile($this->supplier, $this->period, $draft['id'], $draft['revision_no'] + 1, $this->actor));
    }

    public function testPinIsRejectedInLockedPeriod(): void
    {
        $draft = $this->open(['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy']);
        $this->legal('narrow_vpp', 'association', 0);
        $this->pdo->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $this->period]);
        $this->expectFailure('nonprofit_tax_period_locked', 409,
            fn () => $this->drafts()->pinLegalProfile($this->supplier, $this->period, $draft['id'], $draft['revision_no'], $this->actor));
    }

    public function testA9bManualDraftWithoutIdentityIsNotComparedWithSvjModuleAndCannotBePinned(): void
    {
        $period = $this->period(2023);
        $method = ['mode' => 'documented_manual', 'valid_from' => '2023-01-01', 'valid_to' => '2023-12-31',
            'legal_basis' => 'Syntetický právní rozbor', 'evidence' => 'Syntetický podklad metody'];
        $old = $this->drafts()->openCurrent($this->supplier, $period, ['kind' => 'documented', 'evidence' => 'Syntetické'], $method, $this->actor);
        (new PropertyProfileRepository($this->db))->save($this->supplier, ['expected_revision' => 0, 'effective_from' => '2023-01-01T00:00:00Z',
            'effective_to' => null, 'enabled' => true, 'reason' => 'Synthetic SVJ module', 'title_ref' => 'Synthetic legal title',
            'idempotency_key' => 'svj-module-a9b-' . $this->supplier], $this->actor);
        // Vědomá odchylka od legacy (R5): ruční koncept bez doložené identity se nekontroluje ani proti modulu SVJ.
        self::assertSame('none', J6LegalProfileMatch::state($old['header'], $this->match()->context($this->supplier, $period))['state']);
        $this->preview($old, $period);
        $this->legal('svj', 'svj', 0, 'Synthetic declaration', $period);
        $state = J6LegalProfileMatch::state($old['header'], $this->match()->context($this->supplier, $period));
        self::assertSame(['manual', false], [$state['state'], $state['pinnable']]);
        $this->expectFailure('nonprofit_tax_legal_profile_conflict', 409,
            fn () => $this->drafts()->pinLegalProfile($this->supplier, $period, $old['id'], $old['revision_no'], $this->actor));
    }

    public function testDirectlyOpenedHeaderMustHaveValidPinShape(): void
    {
        $this->expectFailure('nonprofit_tax_facts_incomplete', 422, fn () => $this->drafts()->open($this->supplier, $this->period, str_repeat('a', 64),
            ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'x'], 'legal_profile' => ['id' => 1, 'revision_no' => 1]], $this->actor));
        self::assertSame(0, $this->draftCount());
    }

    private function period(int $year): int
    {
        $period = (new AccountingPeriodRepository($this->db))->create($this->supplier, $year, $year . '-01-01', $year . '-12-31');
        $this->pdo->prepare("INSERT IGNORE INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,?,'cz_504')")
            ->execute([$this->supplier, $year . '-01-01']);
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplier, $year . '-01-01');
        return $period;
    }

    private function legal(string $kind, string $entity, int $expected, string $evidence = 'Synthetic statutes', ?int $period = null): array
    {
        return (new NonprofitLegalProfileService($this->db))->confirm($this->supplier, $period ?? $this->period,
            ['expected_revision' => $expected, 'idempotency_key' => 'legal-' . $expected . '-' . ($period ?? $this->period),
                'reason' => 'Synthetic review', 'profile' => ['kind' => $kind, 'entity_type' => $entity,
                    'main_activity_nonbusiness' => true, 'legal_basis' => 'zdp_17a', 'evidence' => $evidence]], $this->actor);
    }

    private function open(array $profile): array
    {
        return $this->drafts()->openCurrent($this->supplier, $this->period, $profile, null, $this->actor);
    }

    private function drafts(): J6AssessmentDraftService
    {
        return $this->container->get(J6AssessmentDraftService::class);
    }

    private function match(): J6LegalProfileMatch
    {
        return $this->container->get(J6LegalProfileMatch::class);
    }

    private function state(array $draft): array
    {
        return J6LegalProfileMatch::state($draft['header'], $this->match()->context($this->supplier, $draft['period_id']));
    }

    private function context(array $draft): J6DraftContext
    {
        return new J6DraftContext($this->supplier, $draft['period_id'], $draft['id'], $draft['revision_no'], $this->actor, $draft['source_input_hash']);
    }

    private function preview(array $draft, ?int $period = null): \MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationReceipt
    {
        $receipt = $this->container->get(J6AssessmentPreviewService::class)->prepare($this->context($draft));
        $this->previewTokens[] = $receipt->previewToken;
        return $receipt;
    }

    private function confirm(array $draft, string $key): \MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentCompletion
    {
        $head = $this->container->get(J6AssessmentReadService::class)->historyHeader($this->supplier, $draft['period_id']);
        return $this->container->get(J6AssessmentConfirmationService::class)->confirm($this->context($draft),
            'synthetic-legal-' . $key . '-' . $this->supplier, 'Syntetické potvrzení',
            ($head['status'] ?? null) === 'confirmed' ? $head['assessment_id'] : null);
    }

    private function readiness(): array
    {
        $result = $this->container->get(NonprofitDppoReadiness::class)->check($this->supplier,
            (new AccountingPeriodRepository($this->db))->findById($this->supplier, $this->period));
        return array_column($result['steps'], null, 'step');
    }

    private function draftCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM nonprofit_tax_drafts WHERE supplier_id=?');
        $stmt->execute([$this->supplier]);
        return (int) $stmt->fetchColumn();
    }

    private function expectFailure(string $code, int $status, callable $work): void
    {
        try {
            $work();
            self::fail('Expected ' . $code . '.');
        } catch (NonprofitTaxAssessmentException $error) {
            self::assertSame([$code, $status], [$error->errorCode, $error->httpStatus], $error->getMessage());
        }
    }
}
