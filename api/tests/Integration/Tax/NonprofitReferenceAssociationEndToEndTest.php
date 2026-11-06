<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoAcceptanceService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingsLedger;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingsService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingUseGuard;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Referenční spolek TJ Příklad (syntetický etalon E0) od zdrojových operací přes
 * daňové posouzení po druzích činností až po DPPO 504: základ 100 000 Kč, snížení
 * podle § 20 odst. 7 o 100 000 Kč, daň 0 Kč, úspora 21 000 Kč a její použití
 * v následujícím zdaňovacím období.
 *
 * Etalon popisuje kalendářní rok 2026. Připnutý formulář DPPDP9 05.01.01 ale začátek
 * roku 2026 nepřijímá (finalizace, XML i podání jsou pro něj zavřené), proto test
 * používá stejné částky v kalendářním roce 2025, který spadá do týchž pravidel 2024-2026.
 * Společná režie O-13/O-14 se zaúčtuje rovnou do materializovaných kombinací; samotná
 * alokace je pokrytá testy společných alokací.
 */
final class NonprofitReferenceAssociationEndToEndTest extends TestCase
{
    use IsolatedSupplierTrait;

    private \DI\Container $container;
    private Connection $db;
    private PDO $pdo;
    private int $supplier;
    private int $actor;
    private int $period;
    private int $year = 2025;
    private array $catalog;
    private array $kinds = [];
    private array $accounts = [];
    private array $lines = [];

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode='double_entry',taxpayer_type='po' WHERE id=?")->execute([$this->supplier]);
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2025-01-01','cz_504')")
            ->execute([$this->supplier]);
        $this->period = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2025, '2025-01-01', '2025-12-31');
        $this->catalog = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2025-01-01');
        foreach (['sport', 'education', 'rental', 'membership', 'general_support', 'culture_free_events'] as $code) {
            $this->pdo->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,?,?)')
                ->execute([$this->catalog['activity_kind']['id'], $this->supplier, strtoupper($code), 'Syntetický druh ' . $code]);
            $this->kinds[$code] = (int) $this->pdo->lastInsertId();
        }
        $types = ['221' => 'asset', '022' => 'asset', '082' => 'asset', '348' => 'asset', '321' => 'liability', '901' => 'equity', '931' => 'equity', '932' => 'equity',
            '501' => 'expense', '518' => 'expense', '551' => 'expense',
            '602' => 'revenue', '649' => 'revenue', '682' => 'revenue', '684' => 'revenue', '691' => 'revenue'];
        foreach ($types as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,?,?,?)')
                ->execute([$this->supplier, (string) $code, 'Syntetický účet ' . $code, $type]);
            $this->accounts[(string) $code] = (int) $this->pdo->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testReferenceAssociationReachesZeroTaxSavingAndNextPeriodUse(): void
    {
        $this->postReferenceYear();
        (new NonprofitLegalProfileService($this->db))->confirm($this->supplier, $this->period, $this->legalRequest('tj-legal-2025'), $this->actor);

        $j6 = $this->container->get(NonprofitTaxAssessmentService::class);
        $snapshot = $j6->read($this->supplier, $this->period)['snapshot'];
        self::assertSame([], $snapshot['findings']);
        self::assertSame(13040000, $snapshot['accounting_result_cents']);
        $confirmed = $j6->confirm($this->supplier, $this->period, $this->referenceAssessment(), $snapshot['input_hash'],
            'Syntetické posouzení TJ Příklad', 'tj-j6-2025', $this->actor);
        $result = $confirmed['result'];
        self::assertSame(13040000, $result['accounting_result_cents']);
        self::assertSame(10000000, $result['assessed_result_cents']);
        $rows = array_column($result['rows'], null, 'id');
        self::assertSame(2500000, $rows[$this->lines['O-08']]['deductible_cents']);
        self::assertSame(5500000, $rows[$this->lines['O-08']]['funded_excluded_cents']);
        self::assertSame('subject', $rows[$this->lines['O-01']]['classification']['regime']);
        self::assertSame('exempt', $rows[$this->lines['O-04']]['classification']['regime']);
        self::assertSame('exempt', $rows[$this->lines['O-05']]['classification']['regime']);
        self::assertSame('out_of_scope', $rows[$this->lines['O-06']]['classification']['regime']);
        self::assertSame('out_of_scope', $rows[$this->lines['O-21']]['classification']['regime']);

        self::assertSame('return', $rows[$this->lines['O-01']]['classification']['method']);
        $this->saveStatementNotes(2025, $this->period);
        $service = $this->container->get(TaxReturnService::class);
        $read = $service->getReturn($this->supplier, 2025, 'po', $this->actor);
        $draft = $service->saveInputs($this->supplier, 2025, 'po', ['nonprofit' => ['reduction_kc' => 100000,
            'reduction_evidence' => 'Syntetický předem schválený plán použití úspory']], $read['return']['row_version'], $this->actor, partial: true);
        $saving = $draft['computed']['nonprofit']['saving'];
        self::assertSame(100000, $saving['base_before_reduction_kc']);
        self::assertSame(100000, $saving['reduction_kc']);
        self::assertSame(21000, $saving['tax_without_reduction_kc']);
        self::assertSame(0, $saving['tax_with_reduction_kc']);
        self::assertSame(21000, $saving['saving_kc']);
        self::assertSame(0, (int) $draft['computed']['tax']);

        try {
            $service->finalize($this->supplier, 2025, 'po', $draft['return']['row_version'], $this->actor,
                expectedNonprofitFilingProofHash: $draft['nonprofit_filing_proof_hash']);
            self::fail('DPPO 504 finalization passed without accepted calculation.');
        } catch (TaxReturnException $e) {
            self::assertSame('nonprofit_dppo_calculation_not_accepted', $e->errorCode);
        }
        (new NonprofitDppoAcceptanceService($this->db))->change($this->supplier, NonprofitDppoAcceptanceService::ACCEPT, true,
            'Syntetický protokol porovnání s etalonem TJ Příklad', 'Syntetická akceptace výpočtu', 'tj-accept', null, $this->actor);
        $draft = $service->getReturn($this->supplier, 2025, 'po', $this->actor);
        $final = $service->finalize($this->supplier, 2025, 'po', $draft['return']['row_version'], $this->actor,
            expectedNonprofitFilingProofHash: $draft['nonprofit_filing_proof_hash']);
        self::assertSame('final', $final['return']['status']);
        self::assertSame(0, (int) $final['computed']['tax']);
        $built = $service->buildXml($this->supplier, 2025, 'po');
        self::assertStringContainsString('uv_vyhl="504"', $built['xml']);

        $savings = new NonprofitTaxSavingsService($this->db);
        $source = $savings->context($this->supplier)['sources'][0];
        self::assertSame('2100000', $source['source']['saving_cents']);
        self::assertSame(100000, $source['source']['reduction_kc']);

        $next = $this->nextPeriodWithCost();
        $due = $this->due($next['period']);
        self::assertSame(100000, $due['addback_kc']);
        $source = $savings->context($this->supplier)['sources'][0];
        $input = ['cost_line_id' => $next['line'], 'amount_cents' => '2100000', 'purpose' => 'nonbusiness',
            'evidence' => 'Syntetické použití úspory na nepodnikatelský náklad'];
        $preview = $savings->previewUse($this->supplier, $source['source']['id'], $input);
        $use = $savings->confirmUse($this->supplier, $source['source']['id'], $input, $preview['preview_hash'],
            $preview['head_hash'], 'tj-saving-use', $this->actor);
        self::assertSame('use', $use['operation']);
        self::assertSame(0, $this->due($next['period'])['addback_kc']);
        self::assertSame('0', $savings->context($this->supplier)['sources'][0]['remaining_cents']);

        $guard = new NonprofitTaxSavingUseGuard($this->db);
        self::assertFalse($guard->assertEntryChange($this->supplier, $this->lineEntry($this->lines['O-03']), [], '2025-03-01'));
        try {
            $guard->assertEntryChange($this->supplier, $next['entry'], null, null);
            self::fail('Expense proving the tax saving use was released for change.');
        } catch (PostingException $e) {
            self::assertSame('nonprofit_tax_saving_cost_in_use', $e->errorCode);
        }
    }

    public function testPagedAssessmentConfirmedOverHttpDrivesDppoToZeroTax(): void
    {
        $this->pagedReferenceYearFinal();

        // Změna účetních podkladů po potvrzení: DPPO odmítne zastaralé stránkované posouzení.
        $main = $this->catalog['activity']['values']['main'];
        $this->entry('O-99', '2025-06-01', [['602', 'credit', '1.00', [$main, $this->kinds['education'], $this->catalog['tax_regime']['values']['subject']]],
            ['221', 'debit', '1.00']]);
        try {
            $this->container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitDppoDataProvider::class)->gather($this->supplier, 2025);
            self::fail('Stale paged J6 assessment was accepted by DPPO.');
        } catch (TaxReturnException $e) {
            self::assertSame('nonprofit_dppo_j6_receipt_invalid', $e->errorCode);
        }
    }

    public function testPagedNextPeriodUsesWholeSavingWithoutAddback(): void
    {
        $this->pagedReferenceYearFinal();
        $next = $this->nextPeriodPaged();
        self::assertSame(100000, $this->due($next['period'])['addback_kc']);
        $savings = new NonprofitTaxSavingsService($this->db);
        $source = $savings->context($this->supplier)['sources'][0];
        self::assertTrue($source['eligibility']['ready'], json_encode($source['eligibility']));
        self::assertSame([$next['line']], array_column($source['eligible_costs'], 'cost_line_id'));
        $use = $this->useSaving($savings, $source, $next['line'], '2100000', 'tj-paged-use-full');
        self::assertSame('use', $use['operation']);
        self::assertSame(0, $this->due($next['period'])['addback_kc']);
        self::assertSame('0', $savings->context($this->supplier)['sources'][0]['remaining_cents']);
        try {
            (new NonprofitTaxSavingUseGuard($this->db))->assertEntryChange($this->supplier, $next['entry'], null, null);
            self::fail('Expense proving the tax saving use was released for change.');
        } catch (PostingException $e) {
            self::assertSame('nonprofit_tax_saving_cost_in_use', $e->errorCode);
        }
    }

    public function testPagedNextPeriodPartialUseAddsBackUnusedShare(): void
    {
        $this->pagedReferenceYearFinal();
        $next = $this->nextPeriodPaged();
        $savings = new NonprofitTaxSavingsService($this->db);
        $source = $savings->context($this->supplier)['sources'][0];
        $this->useSaving($savings, $source, $next['line'], '1050000', 'tj-paged-use-half');
        $due = $this->due($next['period']);
        self::assertSame(\MyInvoice\Service\Tax\Nonprofit\NonprofitDppoReduction::unusedAddback(100000, 2100000, 1050000), $due['addback_kc']);
        self::assertSame(50000, $due['addback_kc']);
        self::assertSame('1050000', $savings->context($this->supplier)['sources'][0]['remaining_cents']);
    }

    /**
     * Obě období potvrzená stránkovanou cestou: DPPO druhého období spočítá, finalizuje
     * a vygeneruje XML včetně použití úspory z prvního období. Formulář DPPDP9 05.01.01
     * nepřijímá začátek roku 2026, proto etalon běží v letech 2024 a 2025.
     */
    public function testPagedSecondPeriodDppoFinalizesWithSavingUse(): void
    {
        $next = $this->period;
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2024-01-01','cz_504')")
            ->execute([$this->supplier]);
        $this->year = 2024;
        $this->period = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2024, '2024-01-01', '2024-12-31');
        $this->pagedReferenceYearFinal();
        // Zahajovací zápis 2025 přebírá konečné zůstatky 2024 včetně výsledku ve schvalovacím řízení (931).
        $opening = $this->entry('OPEN-2025', '2025-01-01', [['022', 'debit', '120000.00'], ['221', 'debit', '392000.00'],
            ['082', 'credit', '24000.00'], ['901', 'credit', '357600.00'], ['931', 'credit', '130400.00']], $next);
        $this->pdo->prepare("UPDATE journal_entries SET source_type='opening' WHERE id=?")->execute([$opening]);
        $this->entry('SETTLE-2024', '2025-06-30', [['931', 'debit', '130400.00'], ['932', 'credit', '130400.00']], $next);
        $second = $this->nextPeriodPaged($next);
        $savings = new NonprofitTaxSavingsService($this->db);
        $this->useSaving($savings, $savings->context($this->supplier)['sources'][0], $second['line'], '2100000', 'tj-paged-use-second');

        $this->saveStatementNotes(2025, $next);
        $service = $this->container->get(TaxReturnService::class);
        $read = $service->getReturn($this->supplier, 2025, 'po', $this->actor);
        $draft = $service->saveInputs($this->supplier, 2025, 'po', ['nonprofit' => []], $read['return']['row_version'], $this->actor, partial: true);
        $context = $draft['podklady']['nonprofit_calculation']['data'];
        self::assertSame(2, $context['j6_receipt']['version']);
        self::assertSame('2100000', $draft['podklady']['nonprofit']['saving_due']['sources'][0]['used_cents']);
        self::assertSame(0, $draft['podklady']['nonprofit']['unused_reduction_addback_kc']);
        self::assertSame(0, (int) $draft['computed']['tax']);
        $draft = $service->getReturn($this->supplier, 2025, 'po', $this->actor);
        $final = $service->finalize($this->supplier, 2025, 'po', $draft['return']['row_version'], $this->actor,
            expectedNonprofitFilingProofHash: $draft['nonprofit_filing_proof_hash']);
        self::assertSame('final', $final['return']['status']);
        self::assertSame(0, (int) $final['computed']['tax']);
        self::assertStringContainsString('uv_vyhl="504"', $service->buildXml($this->supplier, 2025, 'po')['xml']);
        // Kontrola uzávěrky obou období čte stránkovaná posouzení bez legacy snímku.
        $closing = $this->container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentGuard::class);
        foreach ([$this->period, $next] as $period) {
            $check = $closing->closingCheck($this->supplier, $period);
            self::assertTrue($check['complete'], json_encode($check['findings']));
        }
        // Krok odložené daně 504 v uzávěrce: náhled i zápis (aktuální čtení) dokládají
        // podklady období stejným otiskem bez legacy snímku zdrojů.
        $deferred = $this->container->get(\MyInvoice\Service\Accounting\Closing\DeferredTax504Service::class);
        foreach ([$this->period, $next] as $period) {
            $preview = $deferred->preview($this->supplier, $period);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $preview['source_hash']);
            self::assertSame($preview['source_hash'], \MyInvoice\Service\Accounting\Closing\DeferredTax504Repository::sourceHash($this->db, $this->supplier, $period, true));
        }
    }

    private function useSaving(NonprofitTaxSavingsService $savings, array $source, int $line, string $cents, string $key): array
    {
        $input = ['cost_line_id' => $line, 'amount_cents' => $cents, 'purpose' => 'nonbusiness',
            'evidence' => 'Syntetické použití úspory na nepodnikatelský náklad'];
        $preview = $savings->previewUse($this->supplier, $source['source']['id'], $input);
        return $savings->confirmUse($this->supplier, $source['source']['id'], $input, $preview['preview_hash'], $preview['head_hash'], $key, $this->actor);
    }

    /** Následující období potvrzené stránkovanou cestou přes HTTP. */
    private function nextPeriodPaged(?int $period = null): array
    {
        $year = $this->year + 1;
        $period ??= (new AccountingPeriodRepository($this->db))->create($this->supplier, $year, $year . '-01-01', $year . '-12-31');
        (new NonprofitLegalProfileService($this->db))->confirm($this->supplier, $period, $this->legalRequest('tj-legal-' . $year . '-paged'), $this->actor);
        $main = $this->catalog['activity']['values']['main'];
        $subject = $this->catalog['tax_regime']['values']['subject'];
        $entry = $this->entry('N-01', $year . '-02-01', [['518', 'debit', '25000.00', [$main, $this->kinds['sport'], $subject]],
            ['221', 'credit', '25000.00']], $period);
        $assessment = ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy spolku TJ Příklad'],
            'activities' => [(string) $this->kinds['sport'] => ['evidence' => 'Syntetická sportovní činnost']],
            'decisions' => [$this->lines['N-01'] => ['title' => 'nonbusiness_cost', 'deductible_cents' => 0,
                'evidence' => 'Syntetický nepodnikatelský náklad hrazený z úspory', 'legal_basis' => 'zdp_20_7']], 'funding' => []];
        $confirmed = $this->confirmPaged($period, $assessment, 'tj-paged-' . $year . '-' . $this->supplier);
        self::assertSame(0, $confirmed['receipt']['summary']['assessed_result_cents']);
        return ['period' => $period, 'entry' => $entry, 'line' => (int) substr($this->lines['N-01'], 5)];
    }
    private function pagedReferenceYearFinal(): void
    {
        $this->postReferenceYear();
        (new NonprofitLegalProfileService($this->db))->confirm($this->supplier, $this->period, $this->legalRequest('tj-legal-paged'), $this->actor);
        $confirmed = $this->confirmPaged($this->period, $this->referenceAssessment(), 'tj-paged-confirm-' . $this->supplier);
        self::assertSame(10000000, $confirmed['receipt']['summary']['assessed_result_cents']);
        $stored = $this->pdo->prepare('SELECT storage_version,legacy_input_hash FROM nonprofit_tax_assessments WHERE supplier_id=? AND id=?');
        $stored->execute([$this->supplier, $confirmed['assessment_id']]);
        self::assertSame(['storage_version' => 'paged_v2', 'legacy_input_hash' => null], $stored->fetch(PDO::FETCH_ASSOC));

        $this->saveStatementNotes($this->year, $this->period);
        $service = $this->container->get(TaxReturnService::class);
        $read = $service->getReturn($this->supplier, $this->year, 'po', $this->actor);
        $draftReturn = $service->saveInputs($this->supplier, $this->year, 'po', ['nonprofit' => ['reduction_kc' => 100000,
            'reduction_evidence' => 'Syntetický předem schválený plán použití úspory']], $read['return']['row_version'], $this->actor, partial: true);
        $saving = $draftReturn['computed']['nonprofit']['saving'];
        self::assertSame(100000, $saving['base_before_reduction_kc']);
        self::assertSame(100000, $saving['reduction_kc']);
        self::assertSame(0, $saving['tax_with_reduction_kc']);
        self::assertSame(21000, $saving['saving_kc']);
        self::assertSame(0, (int) $draftReturn['computed']['tax']);
        self::assertSame(2, $draftReturn['podklady']['nonprofit_calculation']['data']['j6_receipt']['version']);

        (new NonprofitDppoAcceptanceService($this->db))->change($this->supplier, NonprofitDppoAcceptanceService::ACCEPT, true,
            'Syntetický protokol porovnání s etalonem TJ Příklad', 'Syntetická akceptace výpočtu', 'tj-paged-accept', null, $this->actor);
        $draftReturn = $service->getReturn($this->supplier, $this->year, 'po', $this->actor);
        $final = $service->finalize($this->supplier, $this->year, 'po', $draftReturn['return']['row_version'], $this->actor,
            expectedNonprofitFilingProofHash: $draftReturn['nonprofit_filing_proof_hash']);
        self::assertSame('final', $final['return']['status']);
        $built = $service->buildXml($this->supplier, $this->year, 'po');
        self::assertStringContainsString('uv_vyhl="504"', $built['xml']);
        $source = (new NonprofitTaxSavingsService($this->db))->context($this->supplier)['sources'][0];
        self::assertSame('2100000', $source['source']['saving_cents']);
    }

    private function confirmPaged(int $period, array $assessment, string $key): array
    {
        $base = '/periods/' . $period . '/nonprofit-tax';
        [$status, $opened] = $this->http('POST', $base . '/draft', ['profile' => $assessment['profile']]);
        self::assertSame(200, $status, json_encode($opened));
        $draft = $opened['draft'];
        self::assertTrue($opened['capabilities']['source_complete']);
        $path = $base . '/drafts/' . $draft['id'];
        $revision = $draft['revision_no'];
        $patches = [
            'activities' => array_map(static fn (string $id, array $payload): array => ['activity_kind_id' => $id, 'payload' => $payload],
                array_map('strval', array_keys($assessment['activities'])), array_values($assessment['activities'])),
            'decisions' => array_map(static fn (string $id, array $payload): array => ['source_id' => $id, 'payload' => $payload],
                array_keys($assessment['decisions']), array_values($assessment['decisions'])),
            'funding-links' => array_map(static fn (array $link): array => ['source_id' => $link['source_id'], 'cost_id' => $link['cost_id'],
                'payload' => ['amount_cents' => $link['amount_cents'], 'evidence' => $link['evidence']]], $assessment['funding']),
        ];
        foreach ($patches as $collection => $upserts) {
            [$status, $changed] = $this->http('PATCH', $path . '/' . $collection, ['expected_revision' => $revision, 'upserts' => $upserts, 'deletes' => []]);
            self::assertSame(200, $status, $collection . ': ' . json_encode($changed));
            $revision = $changed['draft']['revision_no'];
        }
        [$status, $confirmed] = $this->http('POST', $path . '/confirm', ['expected_revision' => $revision, 'expected_assessment_id' => null,
            'reason' => 'Syntetické stránkované posouzení', 'idempotency_key' => $key]);
        self::assertSame(200, $status, json_encode($confirmed));
        return $confirmed;
    }

    private function http(string $method, string $path, array $body = []): array
    {
        $app = \Slim\Factory\AppFactory::create(new \Slim\Psr7\Factory\ResponseFactory(), $this->container);
        \MyInvoice\Routes::register($app);
        $app->add($this->container->get(\MyInvoice\Middleware\CsrfMiddleware::class));
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest($method, '/api/accounting' . $path)
            ->withParsedBody($body)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'accountant'])
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_SESSION, ['csrf_token' => 'synthetic-tj-csrf'])
            ->withAttribute('auth.effective_role', new \MyInvoice\Security\EffectiveRole(125, 'Synthetic TJ', 'staff', true,
                ['accounting' => 2, 'accounting.periods.close' => 2]))
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(\MyInvoice\Middleware\TenantDomainMiddleware::ATTR_CONTEXT,
                new \MyInvoice\Service\Tenant\TenantDomainContext('canonical', 'example.test', 'https://example.test'))
            ->withHeader('Origin', 'https://example.test')
            ->withHeader('X-CSRF-Token', 'synthetic-tj-csrf');
        $response = $app->handle($request);
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)];
    }

    private function postReferenceYear(): void
    {
        $main = $this->catalog['activity']['values']['main'];
        $economic = $this->catalog['activity']['values']['economic'];
        $regime = $this->catalog['tax_regime']['values'];
        $y = (string) $this->year;
        $this->entry('O-00', $y . '-01-01', [['221', 'debit', '300000.00'], ['901', 'credit', '300000.00']]);
        $operations = [
            ['O-01', '602', 'credit', '100000.00', $main, 'sport', 'subject'],
            ['O-02', '602', 'credit', '100000.00', $main, 'education', 'subject'],
            ['O-03', '602', 'credit', '60000.00', $economic, 'rental', 'subject'],
            ['O-04', '684', 'credit', '40000.00', $main, 'membership', 'exempt'],
            ['O-05', '682', 'credit', '25000.00', $main, 'general_support', 'exempt'],
            ['O-09', '518', 'debit', '57925.93', $main, 'sport', 'subject'],
            ['O-10', '501', 'debit', '20000.00', $main, 'education', 'subject'],
            ['O-11', '518', 'debit', '24969.14', $main, 'education', 'subject'],
            ['O-12', '518', 'debit', '11981.48', $economic, 'rental', 'subject'],
            ['O-14a', '518', 'debit', '12074.07', $main, 'sport', 'subject'],
            ['O-14b', '518', 'debit', '5030.86', $main, 'education', 'subject'],
            ['O-14c', '518', 'debit', '3018.52', $economic, 'rental', 'subject'],
        ];
        foreach ($operations as [$id, $account, $side, $amount, $activity, $kind, $taxRegime]) {
            $this->entry($id, $y . '-03-01', [[$account, $side, $amount, [$activity, $this->kinds[$kind], $regime[$taxRegime]]],
                ['221', $side === 'debit' ? 'credit' : 'debit', $amount]]);
        }
        $this->entry('O-06', $y . '-02-01', [['348', 'debit', '30000.00'],
            ['691', 'credit', '30000.00', [$main, $this->kinds['general_support'], $regime['out_of_scope']]]]);
        $this->entry('O-07', $y . '-02-15', [['221', 'debit', '30000.00'], ['348', 'credit', '30000.00']]);
        $this->entry('O-08', $y . '-03-10', [['501', 'debit', '80000.00', [$main, $this->kinds['sport'], $regime['subject']]],
            ['321', 'credit', '80000.00']]);
        $this->entry('O-15', $y . '-03-20', [['321', 'debit', '80000.00'], ['221', 'credit', '80000.00']]);
        $this->entry('O-16', $y . '-04-01', [['348', 'debit', '72000.00'], ['901', 'credit', '72000.00']]);
        $this->entry('O-17', $y . '-04-15', [['221', 'debit', '72000.00'], ['348', 'credit', '72000.00']]);
        $this->entry('O-18', $y . '-05-01', [['022', 'debit', '120000.00'], ['321', 'credit', '120000.00']]);
        $this->entry('O-19', $y . '-05-10', [['321', 'debit', '120000.00'], ['221', 'credit', '120000.00']]);
        $this->entry('O-20', $y . '-12-31', [['551', 'debit', '24000.00', [$main, $this->kinds['culture_free_events'], $regime['out_of_scope']]],
            ['082', 'credit', '24000.00']]);
        $this->entry('O-21', $y . '-12-31', [['901', 'debit', '14400.00'],
            ['649', 'credit', '14400.00', [$main, $this->kinds['culture_free_events'], $regime['out_of_scope']]]]);
    }

    /** @param list<array{0:string,1:string,2:string,3?:array{0:int,1:int,2:int}}> $lines */
    private function entry(string $id, string $date, array $lines, ?int $period = null): int
    {
        $this->pdo->prepare("INSERT INTO journal_entries(supplier_id,period_id,entry_date,source_type,description) VALUES(?,?,?,'manual',?)")
            ->execute([$this->supplier, $period ?? $this->period, $date, 'Syntetická operace ' . $id]);
        $entry = (int) $this->pdo->lastInsertId();
        foreach ($lines as $number => $line) {
            $this->pdo->prepare('INSERT INTO journal_entry_lines(supplier_id,entry_id,line_no,account_id,side,amount) VALUES(?,?,?,?,?,?)')
                ->execute([$this->supplier, $entry, $number + 1, $this->accounts[$line[0]], $line[1], $line[2]]);
            $lineId = (int) $this->pdo->lastInsertId();
            if (!isset($line[3])) continue;
            $this->lines[$id] = 'line:' . $lineId;
            foreach (['activity', 'activity_kind', 'tax_regime'] as $axis => $type) {
                $this->pdo->prepare("INSERT INTO journal_entry_line_dimensions(supplier_id,line_id,dimension_type_id,dimension_value_id,assignment_origin)
                    VALUES(?,?,?,?,'explicit')")->execute([$this->supplier, $lineId, $this->catalog[$type]['id'], $line[3][$axis]]);
            }
        }
        $this->pdo->prepare('UPDATE journal_entries SET posted_at=NOW() WHERE id=?')->execute([$entry]);
        return $entry;
    }

    private function referenceAssessment(): array
    {
        $evidence = 'Syntetický doklad etalonu TJ Příklad';
        $activities = [];
        foreach ($this->kinds as $code => $kind) $activities[(string) $kind] = ['evidence' => 'Syntetické stanovy: ' . $code];
        $revenue = static fn (string $title, array $facts = []) => ['title' => $title, 'evidence' => $evidence,
            'legal_basis' => 'zdp_18a', 'deductible_cents' => 0] + ($facts === [] ? [] : ['facts' => $facts]);
        $cost = static fn (string $title, int $deductible) => ['title' => $title, 'evidence' => $evidence,
            'legal_basis' => 'zdp_24_25', 'deductible_cents' => $deductible];
        $decisions = [
            $this->lines['O-01'] => $revenue('nonbusiness'),
            $this->lines['O-02'] => $revenue('nonbusiness'),
            $this->lines['O-03'] => $revenue('rent'),
            $this->lines['O-04'] => $revenue('membership', ['qualifying_membership' => true]),
            $this->lines['O-05'] => $revenue('gift', ['claim_exemption' => true, 'qualifying_purpose' => true,
                'election_evidence' => 'Syntetické uplatnění osvobození daru']),
            $this->lines['O-06'] => $revenue('public_operating_grant', ['public_budget' => true]),
            $this->lines['O-21'] => $revenue('public_investment_grant', ['public_budget' => true]),
            $this->lines['O-08'] => $cost('nonbusiness_cost', 8000000),
            $this->lines['O-09'] => $cost('nonbusiness_cost', 5792593),
            $this->lines['O-14a'] => $cost('nonbusiness_cost', 1207407),
            $this->lines['O-10'] => $cost('nonbusiness_cost', 2000000),
            $this->lines['O-11'] => $cost('nonbusiness_cost', 2496914),
            $this->lines['O-14b'] => $cost('nonbusiness_cost', 503086),
            $this->lines['O-12'] => $cost('cost', 1198148),
            $this->lines['O-14c'] => $cost('cost', 301852),
            $this->lines['O-20'] => $cost('excluded_cost', 0),
        ];
        return ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy spolku TJ Příklad'],
            'activities' => $activities, 'decisions' => $decisions, 'funding_sources' => [], 'funding_costs' => [],
            'funding' => [
                ['source_id' => $this->lines['O-06'], 'cost_id' => $this->lines['O-08'], 'amount_cents' => 3000000,
                    'evidence' => 'F-01 Syntetické rozhodnutí obce o účelu provozní dotace'],
                ['source_id' => $this->lines['O-05'], 'cost_id' => $this->lines['O-08'], 'amount_cents' => 2500000,
                    'evidence' => 'F-02 Syntetické rozhodnutí spolku o použití daru'],
            ]];
    }

    private function legalRequest(string $key, int $revision = 0): array
    {
        return ['expected_revision' => $revision, 'idempotency_key' => $key, 'reason' => 'Syntetické právní posouzení spolku',
            'profile' => ['kind' => 'narrow_vpp', 'entity_type' => 'association', 'main_activity_nonbusiness' => true,
                'legal_basis' => 'zdp_17a', 'evidence' => 'Syntetické stanovy spolku TJ Příklad']];
    }

    private function nextPeriodWithCost(): array
    {
        $period = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        (new NonprofitLegalProfileService($this->db))->confirm($this->supplier, $period, $this->legalRequest('tj-legal-2026'), $this->actor);
        $main = $this->catalog['activity']['values']['main'];
        $subject = $this->catalog['tax_regime']['values']['subject'];
        $entry = $this->entry('N-01', '2026-02-01', [['518', 'debit', '25000.00', [$main, $this->kinds['sport'], $subject]],
            ['221', 'credit', '25000.00']], $period);
        $j6 = $this->container->get(NonprofitTaxAssessmentService::class);
        $snapshot = $j6->read($this->supplier, $period)['snapshot'];
        $assessment = ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy spolku TJ Příklad'],
            'activities' => [(string) $this->kinds['sport'] => ['evidence' => 'Syntetická sportovní činnost']],
            'decisions' => [], 'funding' => []];
        foreach ($snapshot['sources'] as $row) {
            $assessment['decisions'][$row['id']] = ['title' => 'nonbusiness_cost', 'deductible_cents' => 0,
                'evidence' => 'Syntetický nepodnikatelský náklad hrazený z úspory', 'legal_basis' => 'zdp_20_7'];
        }
        $j6->confirm($this->supplier, $period, $assessment, $snapshot['input_hash'], 'Syntetické posouzení dalšího období', 'tj-j6-2026', $this->actor);
        return ['period' => $period, 'entry' => $entry, 'line' => (int) substr($this->lines['N-01'], 5)];
    }

    private function due(int $period): array
    {
        return (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->transaction(function () use ($period): array {
            (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->lockPeriods($this->supplier);
            return (new NonprofitTaxSavingsLedger($this->db))->due($this->supplier, $period);
        });
    }

    private function lineEntry(string $sourceId): int
    {
        $stmt = $this->pdo->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=?');
        $stmt->execute([$this->supplier, (int) substr($sourceId, 5)]);
        return (int) $stmt->fetchColumn();
    }

    private function saveStatementNotes(int $year, int $period): void
    {
        $notes = $this->container->get(\MyInvoice\Service\Accounting\Reports\StatementNotesService::class);
        foreach ($notes->build($this->supplier, $period)['sections'] as $section) {
            $notes->saveSection($this->supplier, $year, $section['key'], 'Syntetická zkontrolovaná příloha', $this->actor);
        }
    }
}
