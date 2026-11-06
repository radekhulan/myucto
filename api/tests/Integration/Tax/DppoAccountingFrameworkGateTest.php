<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Epo\EpoDirectSubmissionService;
use MyInvoice\Service\Epo\EpoSubmissionException;
use MyInvoice\Service\Epo\Isds\TaxSubmissionIsdsService;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\DefaultSubmissionArtifactResolver;
use MyInvoice\Service\Tax\Return\AccountingDecreeService;
use MyInvoice\Service\Tax\Return\DppoReturnDataProvider;
use MyInvoice\Service\Tax\Return\Dppo504CapabilityService;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DppoAccountingFrameworkGateTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private int $supplierId;
    private TaxReturnService $service;
    private DppoReturnDataProvider $provider;
    private \Psr\Container\ContainerInterface $container;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->container = $container;
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $pdo->beginTransaction();
        $constants = \MyInvoice\Service\Tax\TaxConstants::forYear(2026);
        $constants['year'] = 2027;
        $pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')
            ->execute([2027, json_encode($constants, JSON_THROW_ON_ERROR)]);
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET taxpayer_type = 'po', accounting_mode = 'double_entry', tax_accounting_decree = '504' WHERE id = ?")
            ->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier_accounting_modes WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare("INSERT INTO supplier_accounting_modes (supplier_id, effective_from, accounting_mode) VALUES (?, '1900-01-01', 'double_entry')")
            ->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier_accounting_frameworks WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '1900-01-01', 'cz_500'), (?, '2026-07-01', 'cz_504')")
            ->execute([$this->supplierId, $this->supplierId]);
        $pdo->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status) VALUES (?, 2026, '2025-07-01', '2026-06-30', 'open'), (?, 2027, '2026-07-01', '2027-06-30', 'open')")
            ->execute([$this->supplierId, $this->supplierId]);
        $this->service = $container->get(TaxReturnService::class);
        $this->provider = $container->get(DppoReturnDataProvider::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testDirectProviderRejects504BeforeCollecting500LedgerData(): void
    {
        $this->expectGate(fn () => $this->provider->gather($this->supplierId, 2027),'nonprofit_dppo_ruleset_not_supported');
    }

    public static function operations(): array
    {
        return array_map(static fn (string $method): array => [$method], [
            'getReturn', 'previewReadOnly', 'balanceDuePreview', 'balanceDueReadOnly',
            'saveInputs', 'finalize', 'previewXml', 'buildXml', 'generateXml', 'prefinalizeCheck', 'reportSource', 'reopen',
        ]);
    }

    #[DataProvider('operations')]
    public function test504CannotUseReturnOperations(string $method): void
    {
        $returns = new TaxReturnRepository($this->db);
        $returns->create($this->supplierId, 2027, 'po', [], null);
        $args = [$this->supplierId, 2027, 'po'];
        $args = match ($method) {
            'getReturn', 'generateXml' => [...$args, null],
            'saveInputs' => [...$args, ['tax_paid_advances' => 123], 1, null],
            'finalize', 'reopen' => [...$args, 1, null],
            default => $args,
        };
        $this->expectGate(fn () => $this->service->$method(...$args),'nonprofit_dppo_ruleset_not_supported');
        self::assertSame(1, $returns->find($this->supplierId, 2027, 'po')['row_version']);
    }

    public function testFiscalPeriodAndHistoricalModeOverrideCurrentManualDecree(): void
    {
        $decrees = new AccountingDecreeService($this->db);
        self::assertSame('500', $decrees->forYear($this->supplierId, 2026));
        self::assertSame('504', $decrees->forYear($this->supplierId, 2027));
        $data = $this->provider->gather($this->supplierId, 2026);
        self::assertSame('500', $data['accounting_decree']);
        self::assertSame('2026-06-30', $data['period']['ends_on']);

        $this->db->pdo()->prepare("INSERT INTO supplier_accounting_modes (supplier_id, effective_from, accounting_mode) VALUES (?, '2026-07-01', 'tax_evidence')")
            ->execute([$this->supplierId]);
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence', tax_accounting_decree = '501' WHERE id = ?")
            ->execute([$this->supplierId]);
        self::assertSame('500', $decrees->forYear($this->supplierId, 2026));
        self::assertSame('501', $decrees->forDate($this->supplierId, '2026-07-01'));
        self::assertSame('501', $decrees->assertDppoSupported($this->supplierId, 2027));
        $this->db->pdo()->prepare("UPDATE supplier SET tax_accounting_decree = '504' WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->expectGate(fn () => $decrees->assertDppoSupported($this->supplierId, 2027),'nonprofit_dppo_accounting_mode_required');
    }

    /** Rámec 500 nesmí v nálezech XML přebít ručně nastavenou vyhlášku 502 (pojišťovna). */
    public function testXmlFindingsKeepManualDecreeOutsideFramework(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET tax_accounting_decree = '502' WHERE id = ?")
            ->execute([$this->supplierId]);
        $built = $this->service->buildXml($this->supplierId, 2026, 'po');
        self::assertContains('accounting_decree_unsupported', array_column($built['unsupported_cases'], 'key'));
    }

    public function testMissingPeriodDoesNotBypassFrameworkGuard(): void
    {
        $this->expectGate(fn () => $this->provider->gather($this->supplierId, 2028),'nonprofit_dppo_period_required');
    }

    public function test504InsidePeriodCannotHideBetween500Endpoints(): void
    {
        $this->db->pdo()->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '2025-10-01', 'cz_504'), (?, '2026-01-01', 'cz_500')")
            ->execute([$this->supplierId, $this->supplierId]);
        $decrees = new AccountingDecreeService($this->db);
        self::assertSame('500', $decrees->forDate($this->supplierId, '2025-07-01'));
        self::assertSame('500', $decrees->forDate($this->supplierId, '2026-06-30'));
        $this->expectGate(fn () => $this->provider->gather($this->supplierId, 2026),'nonprofit_dppo_mixed_framework');
        $this->expectGate(fn () => $this->service->buildXml($this->supplierId, 2026, 'po'),'nonprofit_dppo_mixed_framework');
        $this->expectGate(fn () => $decrees->assertDppoSubmissionSupported($this->supplierId, [
            'form_code' => 'dppdp9', 'period_year' => 2026,
            'xml_content' => '<Pisemnost><DPPDP9><VetaD zdobd_od="1.7.2025" zdobd_do="30.6.2026" uv_vyhl="500"/></DPPDP9></Pisemnost>',
        ]),'nonprofit_dppo_mixed_framework');
    }

    public function testHistoricalModeChangeInside504FrameworkCannotHideBetweenManual500Endpoints(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework = 'cz_504' WHERE supplier_id = ?")
            ->execute([$this->supplierId]);
        $pdo->prepare("UPDATE supplier_accounting_modes SET accounting_mode = 'tax_evidence' WHERE supplier_id = ?")
            ->execute([$this->supplierId]);
        $pdo->prepare("UPDATE supplier SET tax_accounting_decree = '500' WHERE id = ?")
            ->execute([$this->supplierId]);
        $decrees = new AccountingDecreeService($this->db);
        self::assertSame('500', $decrees->assertDppoSupported($this->supplierId, 2026));
        $pdo->prepare("INSERT INTO supplier_accounting_modes (supplier_id, effective_from, accounting_mode) VALUES (?, '2025-10-01', 'double_entry'), (?, '2026-01-01', 'tax_evidence')")
            ->execute([$this->supplierId, $this->supplierId]);
        self::assertSame('500', $decrees->forDate($this->supplierId, '2025-07-01'));
        self::assertSame('500', $decrees->forDate($this->supplierId, '2026-06-30'));
        $this->expectGate(fn () => $decrees->assertDppoSupported($this->supplierId, 2026),'nonprofit_dppo_mixed_framework');
    }

    public function testHistorical500DraftXmlIsByteIdenticalAfterCurrent504AndManualDecreeChange(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("DELETE FROM supplier_accounting_frameworks WHERE supplier_id = ? AND effective_from = '2026-07-01'")
            ->execute([$this->supplierId]);
        $pdo->prepare("UPDATE supplier SET tax_accounting_decree = '500' WHERE id = ?")->execute([$this->supplierId]);
        $before = $this->service->buildXml($this->supplierId, 2026, 'po')['xml'];
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '2026-07-01', 'cz_504')")
            ->execute([$this->supplierId]);
        $pdo->prepare("UPDATE supplier SET tax_accounting_decree = '504' WHERE id = ?")->execute([$this->supplierId]);
        self::assertSame($before, $this->service->buildXml($this->supplierId, 2026, 'po')['xml']);
    }

    public function testRejected504DoesNotCreateDraft(): void
    {
        $this->expectGate(fn () => $this->service->getReturn($this->supplierId, 2027, 'po', null),'nonprofit_dppo_ruleset_not_supported');
        self::assertNull((new TaxReturnRepository($this->db))->find($this->supplierId, 2027, 'po'));
    }

    public function testFinalComputedCacheCannotBypass504Guard(): void
    {
        $returns = new TaxReturnRepository($this->db);
        $returns->create($this->supplierId, 2027, 'po', [], null);
        $returns->finalize($this->supplierId, 2027, 'po', ['computed' => ['balance_due' => 123.0]], 1);
        foreach (['getReturn', 'previewReadOnly', 'balanceDuePreview', 'balanceDueReadOnly', 'buildXml'] as $method) {
            $args = [$this->supplierId, 2027, 'po'];
            if ($method === 'getReturn') {
                $args[] = null;
            }
            $this->expectGate(fn () => $this->service->$method(...$args),'nonprofit_dppo_ruleset_not_supported');
        }
    }

    public function testHistoricalFinal500RemainsReadable(): void
    {
        $returns = new TaxReturnRepository($this->db);
        $row = $returns->create($this->supplierId, 2026, 'po', [], null);
        $xml = '<Pisemnost><DPPDP9><VetaD zdobd_od="01.07.2025" zdobd_do="30.06.2026" uv_vyhl="500"/></DPPDP9></Pisemnost>';
        $userId = (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $userId);
        $computed = ['computed' => ['balance_due' => 123.0], 'prefinalize_check' => []];
        $snapshotId = $returns->createSnapshot((int) $row['id'], $this->supplierId, $computed, [], $xml, 'passed', [], $userId);
        $returns->finalize($this->supplierId, 2026, 'po', $computed, 1, snapshotId: $snapshotId);
        self::assertEquals(123.0, $this->service->previewReadOnly($this->supplierId, 2026, 'po')['result']['balance_due']);
        self::assertSame(123.0, $this->service->balanceDueReadOnly($this->supplierId, 2026, 'po')['balance_due']);
        self::assertEquals(123.0, $this->service->getReturn($this->supplierId, 2026, 'po', null)['computed']['balance_due']);
        self::assertSame($xml, $this->service->buildXml($this->supplierId, 2026, 'po')['xml']);
    }

    public static function submissionChannels(): array
    {
        return [['direct'], ['isds'], ['queued']];
    }

    #[DataProvider('submissionChannels')]
    public function testArchived504CannotBeSentThroughAnyChannel(string $channel): void
    {
        $xml = '<Pisemnost><DPPDP9><VetaD zdobd_od="01.07.2026" zdobd_do="30.06.2027" uv_vyhl="504" typ_dapdpp="A"/></DPPDP9></Pisemnost>';
        $id = (new TaxSubmissionRepository($this->db))->archive($this->supplierId, 'dppdp9', 2026, null, null, $xml, [], 'passed', [], null);
        try {
            if ($channel === 'direct') {
                $method = new \ReflectionMethod(EpoDirectSubmissionService::class, 'validatedSubmission');
                $method->invoke($this->container->get(EpoDirectSubmissionService::class), $id, $this->supplierId);
            } elseif ($channel === 'isds') {
                $this->container->get(TaxSubmissionIsdsService::class)->enqueue($this->supplierId, $id, null);
            } else {
                $this->container->get(DefaultSubmissionArtifactResolver::class)->resolve($this->supplierId, 'tax_submission', $id);
            }
            self::fail('Archivované DPPO 504 nesmí být odesláno.');
        } catch (EpoSubmissionException|SubmissionChannelException $e) {
            self::assertSame('nonprofit_dppo_ruleset_not_supported', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    public function testHistorical500SubmissionStillPassesTransportGuard(): void
    {
        $xml = '<Pisemnost><DPPDP9><VetaD zdobd_od="01.07.2025" zdobd_do="30.06.2026" uv_vyhl="500"/></DPPDP9></Pisemnost>';
        $id = (new TaxSubmissionRepository($this->db))->archive($this->supplierId, 'dppdp9', 2026, null, null, $xml, [], 'passed', [], null);
        $method = new \ReflectionMethod(EpoDirectSubmissionService::class, 'validatedSubmission');
        self::assertSame($id, $method->invoke($this->container->get(EpoDirectSubmissionService::class), $id, $this->supplierId)['id']);
        self::assertSame($xml, $this->container->get(DefaultSubmissionArtifactResolver::class)->resolve($this->supplierId, 'tax_submission', $id)['bytes']);
    }

    public function testRejectedQueued504IsFailedWithoutLeavingSendingState(): void
    {
        $xml = '<Pisemnost><DPPDP9><VetaD zdobd_od="01.07.2026" zdobd_do="30.06.2027" uv_vyhl="504" typ_dapdpp="A"/></DPPDP9></Pisemnost>';
        $artifactId = (new TaxSubmissionRepository($this->db))->archive($this->supplierId, 'dppdp9', 2027, null, null, $xml, [], 'passed', [], null);
        $userId = (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $outbox = new \MyInvoice\Repository\Submission\SubmissionOutboxRepository($this->db);
        $queued = $outbox->enqueue([
            'supplier_id' => $this->supplierId, 'environment' => 'test', 'channel' => 'isds',
            'agenda_code' => 'DPPDP9', 'recipient_id' => null, 'recipient_box_id' => 'abcdefg',
            'subject' => 'Syntetické DPPO', 'artifact_kind' => 'tax_submission', 'artifact_id' => $artifactId,
            'artifact_filename' => 'synthetic-dppo.xml', 'artifact_sha256' => hash('sha256', $xml),
            'correlation_reference' => 'TEST-DPPO-' . $artifactId, 'created_by' => $userId,
        ], 'synthetic-504-gate-' . $artifactId);
        $id = (int) $queued['row']['id'];
        $context = new \MyInvoice\Service\Submission\Channel\ChannelContext(
            $this->supplierId, 'test', new \MyInvoice\Service\Submission\Channel\ChannelCredentials('zzzzzzz', 'certificate'),
        );
        $service = $this->container->get(\MyInvoice\Service\Submission\SubmissionOutboxService::class);

        $result = $service->confirmAndSend($this->supplierId, $id, $userId, $context);
        self::assertFalse($result['dispatched']);
        self::assertSame('failed', $result['row']['dispatch_state']);
        self::assertSame('nonprofit_dppo_ruleset_not_supported', $result['row']['last_error_code']);
        self::assertSame('failed', $outbox->find($this->supplierId, $id)['dispatch_state']);
        self::assertFalse($service->confirmAndSend($this->supplierId, $id, $userId, $context)['dispatched']);
        $attempts = $this->db->pdo()->prepare('SELECT COUNT(*) FROM submission_outbox_attempts WHERE supplier_id = ? AND outbox_id = ?');
        $attempts->execute([$this->supplierId, $id]);
        self::assertSame(0, (int) $attempts->fetchColumn());
    }

    public function testUnsupportedDppoEstimateDoesNotBreakUnrelatedTaxForecasts(): void
    {
        (new TaxReturnRepository($this->db))->create($this->supplierId, 2027, 'po', ['filing_deadline' => '2028-06-30'], null);
        $obligations = $this->container->get(\MyInvoice\Service\Accounting\Obligations\ExistingObligationSourceService::class);

        self::assertNull($obligations->dppoForecastForYear($this->supplierId, 2027, '2028-01-01', '2028-12-31'));
        self::assertSame([], $obligations->taxForecasts($this->supplierId, '2028-01-01', '2028-12-31'));
        $this->expectGate(fn () => $this->service->getReturn($this->supplierId, 2027, 'po', null),'nonprofit_dppo_ruleset_not_supported');
    }

    public function test2026CalendarPeriodAllowsComputeButFinalizationCapabilityFails422():void
    {
        $pdo=$this->db->pdo();$pdo->prepare('DELETE FROM accounting_periods WHERE supplier_id=?')->execute([$this->supplierId]);$pdo->prepare('DELETE FROM supplier_accounting_frameworks WHERE supplier_id=?')->execute([$this->supplierId]);$pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework)VALUES(?,'1900-01-01','cz_504')")->execute([$this->supplierId]);$pdo->prepare("INSERT INTO accounting_periods(supplier_id,fiscal_year,starts_on,ends_on,status)VALUES(?,2026,'2026-01-01','2026-12-31','open')")->execute([$this->supplierId]);
        $decrees=new AccountingDecreeService($this->db);self::assertSame('504',$decrees->assertDppoSupported($this->supplierId,2026,Dppo504CapabilityService::COMPUTE));$row=(new TaxReturnRepository($this->db))->create($this->supplierId,2026,'po',[],null);$this->expectGate(fn()=>$this->service->finalize($this->supplierId,2026,'po',$row['row_version'],null),'nonprofit_dppo_form_period_not_supported');self::assertSame('draft',(new TaxReturnRepository($this->db))->find($this->supplierId,2026,'po')['status']);
    }

    public function testUnsafeStaticActivationRemainsClosed():void
    {$this->expectGate(fn()=>AccountingDecreeService::assertSupportedDecree('504'),'dppo_accounting_framework_not_supported');}

    private function expectGate(callable $operation,string $expectedCode): void
    {
        try {
            $operation();
            self::fail('DPPO 504 musí být odmítnuto.');
        } catch (TaxReturnException $e) {
            self::assertSame($expectedCode, $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }
}
