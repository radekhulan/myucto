<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Action\Invoice\ListInvoicesAction;
use MyInvoice\Action\PurchaseInvoice\ListPurchaseInvoicesAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\DimensionListSummaryRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Viditelnost dimenzí mezi firmami (Firma → Dimenze).
 *
 * Firemní typ vidí jen jeho firma, globální typ všechny firmy jeho skupiny a nikdo
 * jiný. Ověřuje se přes DimensionRepository (predikát visibleSql) i přes validaci
 * vstupu v DimensionService — cizí hodnota nesmí projít ani přímým id (BOLA).
 * Připojit firmu ke skupině smí jen ten, kdo má přístup k některé firmě skupiny.
 */
#[Group('integration')]
final class DimensionVisibilityTest extends TestCase
{
    private Connection $db;
    private DimensionService $service;
    private DimensionRepository $repo;
    private int $czId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(DimensionService::class);
            $this->repo = $container->get(DimensionRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->czId === 0 || $this->currencyId === 0 || $this->vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testGlobalTypeIsSharedInsideGroupOnly(): void
    {
        $parent = $this->supplier('Mateřská a.s.');
        $spv = $this->supplier('SPV s.r.o.');
        $outsider = $this->supplier('Cizí s.r.o.');

        $groupId = $this->service->createGroup($parent, 'Skupina test');
        $this->service->joinGroup($spv, $groupId, [$parent, $spv], false);
        $this->repo->forgetGroupCache();

        $global = $this->service->createType($parent, ['code' => 'projekt', 'name' => 'Projekt', 'kind' => 'project', 'level' => 'global']);
        $firm = $this->service->createType($parent, ['code' => 'stredisko', 'name' => 'Středisko', 'kind' => 'cost_center']);
        $project = $this->service->createValue($parent, $global['id'], ['code' => 'FVE-01', 'name' => 'Elektrárna']);
        $center = $this->service->createValue($parent, $firm['id'], ['code' => 'REZ', 'name' => 'Režie']);

        self::assertSame(['projekt'], array_column($this->repo->listTypes($spv), 'code'), 'SPV vidí globální typ, ne firemní typ mateřské firmy.');
        self::assertNotNull($this->repo->findValue($spv, $project['id']));
        self::assertNull($this->repo->findValue($spv, $center['id']));
        self::assertSame([], $this->repo->listTypes($outsider), 'Firma mimo skupinu nevidí nic.');
        self::assertNull($this->repo->findValue($outsider, $project['id']));

        // Globální hodnotu smí SPV použít na svém dokladu, cizí firma ne (ani přímým id).
        self::assertSame([$global['id'] => $project['id']], $this->service->normalize($spv, [$global['id'] => $project['id']]));
        $this->expectRejected(fn () => $this->service->normalize($outsider, [$global['id'] => $project['id']]));
        $this->expectRejected(fn () => $this->service->normalize($spv, [$firm['id'] => $center['id']]));
        $this->expectRejected(fn () => $this->service->normalize($spv, [$firm['id'] => $project['id']]));
    }

    public function testSeznamDokladuNacitaDimenzeJenPriZapnutiFirmy(): void
    {
        $supplier = $this->supplier('Seznam dimenzí s.r.o.');
        $type = $this->service->createType($supplier, ['code' => 'stredisko', 'name' => 'Středisko', 'kind' => 'cost_center']);
        $value = $this->service->createValue($supplier, $type['id'], ['code' => 'BRNO', 'name' => 'Brno']);
        $summary = new DimensionListSummaryRepository($this->db);
        $docId = 800001;
        $this->db->pdo()->prepare(
            "INSERT INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id)
             VALUES (?, 'invoice', ?, 0, ?, ?)"
        )->execute([$supplier, $docId, $type['id'], $value['id']]);

        self::assertSame([], $summary->forDocuments($supplier, 'invoice', [$docId]));
        $this->repo->setEnabled($supplier, true);
        self::assertSame([$docId => ['Středisko: BRNO']], $summary->forDocuments($supplier, 'invoice', [$docId]));
        self::assertSame([], $summary->forDocuments($supplier, 'purchase_invoice', [$docId]));

        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on) VALUES (?, 2026, '2026-01-01', '2026-12-31')")
            ->execute([$supplier]);
        $periodId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type) VALUES (?, '501', 'Materiál', 'expense')")
            ->execute([$supplier]);
        $accountId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO journal_entries (supplier_id, period_id, entry_date, description) VALUES (?, ?, '2026-01-01', 'Test dimenze')")
            ->execute([$supplier, $periodId]);
        $entryId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO journal_entry_lines (supplier_id, entry_id, account_id, side, amount) VALUES (?, ?, ?, 'debit', 100)")
            ->execute([$supplier, $entryId, $accountId]);
        $lineId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO journal_entry_line_dimensions (supplier_id, line_id, dimension_type_id, dimension_value_id) VALUES (?, ?, ?, ?)')
            ->execute([$supplier, $lineId, $type['id'], $value['id']]);
        self::assertSame([$entryId => ['Středisko: BRNO']], $summary->forJournalEntries($supplier, [$entryId]));
    }

    public function testSeznamDokladuUkazujeSystemovouHodnotuNazvemBezInternihoKodu(): void
    {
        $supplier = $this->supplier('Systémové dimenze s.r.o.');
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind, system_key) VALUES (?, 'activity', 'Činnost', 'custom', 'activity')")
            ->execute([$supplier]);
        $typeId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name, semantic_key) VALUES (?, ?, 'economic', 'Hospodářská činnost', 'economic')")
            ->execute([$typeId, $supplier]);
        $valueId = (int) $pdo->lastInsertId();
        $this->repo->setEnabled($supplier, true);
        $docId = 800002;
        $pdo->prepare(
            "INSERT INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id)
             VALUES (?, 'invoice', ?, 0, ?, ?)"
        )->execute([$supplier, $docId, $typeId, $valueId]);

        self::assertSame([$docId => ['Činnost: Hospodářská činnost']], (new DimensionListSummaryRepository($this->db))->forDocuments($supplier, 'invoice', [$docId]));
    }

    public function testSeznamDokladuNeslouciStejnePojmenovaneJednotky(): void
    {
        $supplier = $this->supplier('Dvě jednotky 1 s.r.o.');
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind, system_key) VALUES (?, 'unit', 'Jednotka', 'custom', 'unit')")
            ->execute([$supplier]);
        $typeId = (int) $pdo->lastInsertId();
        $ids = [];
        foreach (['unit:12', 'unit:15'] as $key) {
            $pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name, semantic_key) VALUES (?, ?, ?, 'Jednotka 1', ?)")
                ->execute([$typeId, $supplier, $key, $key]);
            $ids[] = (int) $pdo->lastInsertId();
        }
        $this->repo->setEnabled($supplier, true);
        $docId = 800003;
        foreach ($ids as $i => $valueId) {
            $pdo->prepare(
                "INSERT INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id)
                 VALUES (?, 'invoice', ?, ?, ?, ?)"
            )->execute([$supplier, $docId, $i + 1, $typeId, $valueId]);
        }

        self::assertSame(
            [$docId => ['Jednotka: Jednotka 1 (unit:12)', 'Jednotka: Jednotka 1 (unit:15)']],
            (new DimensionListSummaryRepository($this->db))->forDocuments($supplier, 'invoice', [$docId]),
        );
    }

    public function testVolitelneDimenzeVeFakturachVyzadujiUcetniPravo(): void
    {
        $supplier = $this->supplier('Omezený přístup s.r.o.');
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier)
            ->withQueryParams(['filter' => ['include_dimensions' => '1']]);
        $container = Bootstrap::buildContainer();
        foreach ([ListInvoicesAction::class, ListPurchaseInvoicesAction::class] as $class) {
            $response = $container->get($class)($request, (new ResponseFactory())->createResponse());
            self::assertSame(403, $response->getStatusCode(), $class);
        }
    }

    public function testJoiningGroupRequiresAccessToItsMember(): void
    {
        $parent = $this->supplier('Mateřská a.s.');
        $stranger = $this->supplier('Nezávislá s.r.o.');
        $groupId = $this->service->createGroup($parent, 'Skupina test');

        try {
            $this->service->joinGroup($stranger, $groupId, [$stranger], false);
            self::fail('Firma se nesmí připojit ke skupině, jejíž firmy uživatel nespravuje.');
        } catch (DimensionException $e) {
            self::assertSame('not_found', $e->errorCode);
        }
        self::assertNull($this->repo->groupIdOf($stranger));
        self::assertNotContains($groupId, array_column($this->service->groupInfo($stranger, [$stranger])['candidates'], 'id'));
        self::assertContains($groupId, array_column($this->service->groupInfo($stranger, [$stranger, $parent])['candidates'], 'id'));
    }

    public function testTreeDescendantsAndMoveGuards(): void
    {
        $supplier = $this->supplier('Stromová s.r.o.');
        $type = $this->service->createType($supplier, ['code' => 'lokalita', 'name' => 'Lokalita', 'kind' => 'location']);
        $cz = $this->service->createValue($supplier, $type['id'], ['code' => 'CZ', 'name' => 'Česko']);
        $morava = $this->service->createValue($supplier, $type['id'], ['code' => 'MOR', 'name' => 'Morava', 'parent_id' => $cz['id']]);
        $brno = $this->service->createValue($supplier, $type['id'], ['code' => 'BRNO', 'name' => 'Brno', 'parent_id' => $morava['id']]);
        $other = $this->service->createValue($supplier, $type['id'], ['code' => 'SK', 'name' => 'Slovensko']);

        self::assertEqualsCanonicalizing([$cz['id'], $morava['id'], $brno['id']], $this->repo->descendantIds($supplier, $cz['id']));
        self::assertSame([$brno['id']], $this->repo->descendantIds($supplier, $brno['id']));

        try {
            $this->service->updateValue($supplier, $cz['id'], ['parent_id' => $brno['id']]);
            self::fail('Hodnotu nelze přesunout pod vlastní podřízenou.');
        } catch (DimensionException $e) {
            self::assertSame('invalid_parent', $e->errorCode);
        }
        $moved = $this->service->updateValue($supplier, $brno['id'], ['parent_id' => $other['id']]);
        self::assertSame($other['id'], $moved['parent_id']);

        // Použitou hodnotu mazání jen uzavře, nepoužitou smaže.
        self::assertSame(['deleted' => false], $this->service->deleteValue($supplier, $other['id']), 'Hodnota s podřízenou se jen uzavře.');
        self::assertFalse($this->repo->findValue($supplier, $other['id'])['is_active']);
        self::assertSame(['deleted' => true], $this->service->deleteValue($supplier, $brno['id']));

        // Uzavřenou hodnotu nelze nově vybrat, na dokladu, kde už je, zůstat smí.
        $this->expectRejected(fn () => $this->service->normalize($supplier, [$other['id']]));
        self::assertSame([$type['id'] => $other['id']], $this->service->normalize($supplier, [$other['id']], [$other['id']]));
    }

    public function testGlobalValueCannotLinkCompanyRecords(): void
    {
        $parent = $this->supplier('Mateřská a.s.');
        $this->service->createGroup($parent, 'Skupina test');
        $this->repo->forgetGroupCache();
        $global = $this->service->createType($parent, ['code' => 'projekt', 'name' => 'Projekt', 'kind' => 'project', 'level' => 'global']);
        $this->db->pdo()->prepare("INSERT INTO cars (supplier_id, registration) VALUES (?, '1A2 3456')")->execute([$parent]);
        $carId = (int) $this->db->pdo()->lastInsertId();

        $this->expectRejected(fn () => $this->service->createValue($parent, $global['id'], ['code' => 'X', 'name' => 'X', 'car_id' => $carId]));
    }

    private function expectRejected(callable $fn): void
    {
        try {
            $fn();
            self::fail('Očekávaná DimensionException nenastala.');
        } catch (DimensionException $e) {
            self::assertContains($e->errorCode, ['invalid_dimension', 'dimension_closed', 'invalid_reference']);
        }
    }

    private function supplier(string $name): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Test 1", "Praha", "11000", ?, "dimenze@example.invalid", ?, ?, "double_entry")'
        )->execute([$name, $this->czId, $this->currencyId, $this->vatRateId]);
        return (int) $pdo->lastInsertId();
    }
}
