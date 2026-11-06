<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\ProjectProfitService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ekonomika zakázky napříč protistranami (issue #29).
 *
 * Scénář zrcadlí zadání klienta (cestovní kancelář): jedna akce, DVA různí odběratelé
 * a DVA různí dodavatelé. Ověřuje, že:
 *   • `PostingService` orazítkuje řádky deníku zakázkou ze zdrojového dokladu,
 *   • výsledovka po zakázkách sečte výnosy i náklady bez ohledu na protistranu,
 *   • storno zakázku přenese a v marži se vyruší,
 *   • přeřazení už ZAÚČTOVANÉHO dokladu k jiné akci přerazítkuje i deník,
 *   • cizí zakázka jiného tenanta se do součtu nedostane.
 *
 * Vše v jedné transakci, tearDown rollbackne. Soft-skip bez cfg.php (vzor
 * {@see PostingServiceTest}).
 */
#[Group('integration')]
final class ProjectProfitTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private PostingService $posting;
    private ProjectProfitService $profit;
    private JournalEntryRepository $journal;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db      = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->profit  = $container->get(ProjectProfitService::class);
            $this->journal = $container->get(JournalEntryRepository::class);
            $periods       = $container->get(AccountingPeriodRepository::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0
            || $this->userId === 0 || $this->czId === 0
        ) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $seeder->seedForSupplier($this->supplierId);
        $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');

        // Sestava čte deník jen v podvojném účetnictví; rollback stav vrátí.
        $pdo->prepare('UPDATE supplier SET accounting_mode = ? WHERE id = ?')
            ->execute(['double_entry', $this->supplierId]);
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

    /**
     * Jádro issue #29: akce se dvěma odběrateli a dvěma dodavateli dá jednu marži.
     */
    public function testMarginAggregatesAcrossAllCounterparties(): void
    {
        $organizer = $this->client('Pořadatel zakázky s.r.o.', true, false);
        $projectId = $this->project($organizer, 'Turnaj Rakousko 2098', 'AKCE-01');

        $customerA = $this->client('Sportovní klub A', true, false);
        $customerB = $this->client('Sportovní klub B', true, false);
        $hotel     = $this->client('Hotel Alpen GmbH', false, true);
        $bus       = $this->client('Autobusy s.r.o.', false, true);

        $this->postSale($this->sale('FV-2098-001', $customerA, 60_000.00, 12_600.00, $projectId));
        $this->postSale($this->sale('FV-2098-002', $customerB, 40_000.00, 8_400.00, $projectId));
        $this->postPurchase($this->purchase('HOTEL-1', $hotel, 30_000.00, 6_300.00, $projectId));
        $this->postPurchase($this->purchase('BUS-1', $bus, 25_000.00, 5_250.00, $projectId));

        // Náklad BEZ zakázky nesmí ekonomiku akce zatížit.
        $this->postPurchase($this->purchase('KANCELAR-1', $bus, 9_999.00, 2_099.79, null));

        $detail = $this->profit->detail($this->supplierId, $projectId);
        self::assertNotNull($detail);
        self::assertSame('journal', $detail['source']);
        self::assertEqualsWithDelta(100_000.00, $detail['revenue'], 0.01, 'Výnos = obě vydané faktury.');
        self::assertEqualsWithDelta(55_000.00, $detail['cost'], 0.01, 'Náklad = oba dodavatelé, ten bez zakázky ne.');
        self::assertEqualsWithDelta(45_000.00, $detail['margin'], 0.01);
        self::assertEqualsWithDelta(45.0, $detail['margin_percent'], 0.01);

        // Doklady zakázky — čtyři, napříč oběma stranami a čtyřmi protistranami.
        $numbers = array_column($detail['documents'], 'number');
        sort($numbers);
        self::assertSame(['BUS-1', 'FV-2098-001', 'FV-2098-002', 'HOTEL-1'], $numbers);

        // Přehled všech zakázek musí dát totéž jako detail.
        $overview = $this->profit->overview($this->supplierId);
        $row = null;
        foreach ($overview['items'] as $item) {
            if ($item['id'] === $projectId) {
                $row = $item;
            }
        }
        self::assertNotNull($row, 'Zakázka je v přehledu.');
        self::assertEqualsWithDelta(45_000.00, $row['margin'], 0.01);
    }

    /** Doklad se zakázkou orazítkuje KAŽDÝ řádek svého zápisu, ne jen nákladový. */
    public function testEveryJournalLineCarriesProjectDimension(): void
    {
        $organizer = $this->client('Pořadatel s.r.o.', true, false);
        $projectId = $this->project($organizer, 'Akce s dimenzí', 'AKCE-02');
        $vendor    = $this->client('Dodavatel s.r.o.', false, true);

        $entryId = $this->postPurchase($this->purchase('DIM-1', $vendor, 1_000.00, 210.00, $projectId));

        $entry = $this->journal->find($entryId, $this->supplierId);
        self::assertNotNull($entry);
        self::assertNotSame([], $entry['lines']);
        foreach ($entry['lines'] as $line) {
            self::assertSame($projectId, $line['project_id'], 'Každý řádek nese zakázku.');
        }
    }

    /** Storno nese tutéž zakázku → marže akce se vrátí na nulu, ne do mínusu. */
    public function testReversalCancelsOutInMargin(): void
    {
        $organizer = $this->client('Pořadatel s.r.o.', true, false);
        $projectId = $this->project($organizer, 'Zrušená akce', 'AKCE-03');
        $vendor    = $this->client('Dodavatel s.r.o.', false, true);

        $entryId = $this->postPurchase($this->purchase('STORNO-1', $vendor, 5_000.00, 1_050.00, $projectId));
        self::assertEqualsWithDelta(
            5_000.00,
            $this->profit->detail($this->supplierId, $projectId)['cost'],
            0.01,
        );

        $this->posting->reverse($this->supplierId, $entryId, ['user_id' => $this->userId]);

        $after = $this->profit->detail($this->supplierId, $projectId);
        self::assertEqualsWithDelta(0.0, $after['cost'], 0.01, 'Originál + protizápis = nula.');
        self::assertEqualsWithDelta(0.0, $after['margin'], 0.01);
    }

    /** Přeřazení ZAÚČTOVANÉHO dokladu k jiné akci přepíše i dimenzi v deníku. */
    public function testRestampMovesCostBetweenProjects(): void
    {
        $organizer = $this->client('Pořadatel s.r.o.', true, false);
        $fromId    = $this->project($organizer, 'Akce A', 'AKCE-04');
        $toId      = $this->project($organizer, 'Akce B', 'AKCE-05');
        $vendor    = $this->client('Dodavatel s.r.o.', false, true);

        $purchaseId = $this->purchase('PRESUN-1', $vendor, 7_000.00, 1_470.00, $fromId);
        $this->postPurchase($purchaseId);

        $this->db->pdo()->prepare('UPDATE purchase_invoices SET project_id = ? WHERE id = ?')
            ->execute([$toId, $purchaseId]);
        $updated = $this->posting->restampProjectDimension($this->supplierId, 'purchase_invoice', $purchaseId, $toId);
        self::assertGreaterThan(0, $updated, 'Přerazítkoval se aspoň jeden řádek deníku.');

        self::assertEqualsWithDelta(0.0, $this->profit->detail($this->supplierId, $fromId)['cost'], 0.01);
        self::assertEqualsWithDelta(7_000.00, $this->profit->detail($this->supplierId, $toId)['cost'], 0.01);
    }

    public function testProjectActionsPreserveClosedHeaderAndJournal(): void
    {
        $client = $this->client('Syntetický klient', true, true);
        $a = $this->project($client, 'Projekt A', 'CLOSED-A');
        $b = $this->project($client, 'Projekt B', 'CLOSED-B');
        $sale = $this->sale('CLOSED-SALE', $client, 1000.00, 210.00, $a);
        $purchase = $this->purchase('CLOSED-PURCHASE', $client, 1000.00, 210.00, $a);
        $saleEntry = $this->postSale($sale);
        $purchaseEntry = $this->postPurchase($purchase);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE supplier_id = ? AND fiscal_year = ?")
            ->execute([$this->supplierId, self::YEAR]);
        foreach ([
            ['invoices', $sale, $saleEntry, \MyInvoice\Action\Invoice\SetInvoiceProjectAction::class, 'invoice'],
            ['purchase_invoices', $purchase, $purchaseEntry, \MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceProjectAction::class, 'purchase_invoice'],
        ] as [$table, $id, $entry, $class, $source]) {
            $before = $this->journal->find($entry, $this->supplierId)['lines'];
            $action = Bootstrap::buildContainer()->get($class);
            $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/project')
                ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
            foreach ([$b, null] as $project) {
                $response = $action($request->withParsedBody(['project_id' => $project]), new \Slim\Psr7\Response(), ['id' => (string) $id]);
                self::assertSame(409, $response->getStatusCode());
                self::assertSame('period_not_open', json_decode((string) $response->getBody(), true)['error']['code']);
                $header = $this->db->pdo()->prepare("SELECT project_id FROM {$table} WHERE id = ? AND supplier_id = ?");
                $header->execute([$id, $this->supplierId]);
                self::assertSame($a, (int) $header->fetchColumn());
                self::assertSame($before, $this->journal->find($entry, $this->supplierId)['lines']);
            }
            self::assertSame(0, $this->posting->restampProjectDimension($this->supplierId, $source, $id, $a));
            self::assertSame(200, $action($request->withParsedBody(['project_id' => $a]), new \Slim\Psr7\Response(), ['id' => (string) $id])->getStatusCode());
        }
    }

    /**
     * MYU-2026-H48: klientská role bez `projects` zakázku nepřiřadí vůbec a ani
     * s `projects` nepřeřadí zaúčtovaný (zamčený) doklad, který by přerazítkoval deník.
     */
    public function testClientRoleCannotReassignProjectOnLockedDocuments(): void
    {
        $client = $this->client('Syntetický klient', true, true);
        $a = $this->project($client, 'Projekt A', 'LOCK-A');
        $b = $this->project($client, 'Projekt B', 'LOCK-B');
        $sale = $this->sale('LOCK-SALE', $client, 1000.00, 210.00, $a);
        $purchase = $this->purchase('LOCK-PURCHASE', $client, 1000.00, 210.00, $a);
        $saleEntry = $this->postSale($sale);
        $purchaseEntry = $this->postPurchase($purchase);
        $clientWithProjects = new \MyInvoice\Security\EffectiveRole(0, 'Klient se zakázkami', 'client', true, [
            'invoices' => 2, 'purchase_invoices' => 2, 'projects' => 1,
        ]);

        foreach ([
            ['invoices', $sale, $saleEntry, \MyInvoice\Action\Invoice\SetInvoiceProjectAction::class],
            ['purchase_invoices', $purchase, $purchaseEntry, \MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceProjectAction::class],
        ] as [$table, $id, $entry, $class]) {
            $before = $this->journal->find($entry, $this->supplierId)['lines'];
            $action = Bootstrap::buildContainer()->get($class);
            $base = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/project')
                ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
                ->withParsedBody(['project_id' => $b]);

            $legacyClient = $base->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'client']);
            $response = $action($legacyClient, new \Slim\Psr7\Response(), ['id' => (string) $id]);
            self::assertSame(403, $response->getStatusCode());
            self::assertSame('forbidden_permission', json_decode((string) $response->getBody(), true)['error']['code']);

            $delegated = $base->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId])
                ->withAttribute('auth.effective_role', $clientWithProjects);
            $response = $action($delegated, new \Slim\Psr7\Response(), ['id' => (string) $id]);
            self::assertSame(403, $response->getStatusCode());
            self::assertSame('document_locked', json_decode((string) $response->getBody(), true)['error']['code']);

            $header = $this->db->pdo()->prepare("SELECT project_id FROM {$table} WHERE id = ? AND supplier_id = ?");
            $header->execute([$id, $this->supplierId]);
            self::assertSame($a, (int) $header->fetchColumn());
            self::assertSame($before, $this->journal->find($entry, $this->supplierId)['lines']);
        }

        // Nezamčený doklad klientská role se `projects` přiřadit smí.
        $draft = $this->sale('LOCK-OPEN', $client, 500.00, 105.00, null);
        $action = Bootstrap::buildContainer()->get(\MyInvoice\Action\Invoice\SetInvoiceProjectAction::class);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/project')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withAttribute('auth.effective_role', $clientWithProjects)
            ->withParsedBody(['project_id' => $b]);
        self::assertSame(200, $action($request, new \Slim\Psr7\Response(), ['id' => (string) $draft])->getStatusCode());
    }

    /** MYU-2026-H48: detail odběratele vrací zakázky a obrat po zakázkách jen s `projects`. */
    public function testClientDetailHidesProjectsWithoutProjectsPermission(): void
    {
        $customer = $this->client('Odběratel se zakázkou', true, false);
        $projectId = $this->project($customer, 'Tajná zakázka', 'TAJNA-01');
        $this->db->pdo()->prepare('UPDATE projects SET hourly_rate = 1500 WHERE id = ?')->execute([$projectId]);
        $this->sale('FV-TAJNA', $customer, 1000.00, 210.00, $projectId);
        $action = Bootstrap::buildContainer()->get(\MyInvoice\Action\Client\GetClientAction::class);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/clients/' . $customer)
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);

        $client = $action(
            $request->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'client']),
            new \Slim\Psr7\Response(),
            ['id' => (string) $customer],
        );
        self::assertSame(200, $client->getStatusCode());
        $body = (string) $client->getBody();
        $data = json_decode($body, true);
        self::assertSame([], $data['projects']);
        self::assertSame([], $data['revenue_by_project']);
        self::assertSame(1, $data['projects_count'], 'Počet zakázek zůstává kvůli nabídce smazání.');
        self::assertStringNotContainsString('Tajná zakázka', $body);
        self::assertStringNotContainsString('TAJNA-01', $body);

        $staff = json_decode((string) $action(
            $request->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']),
            new \Slim\Psr7\Response(),
            ['id' => (string) $customer],
        )->getBody(), true);
        self::assertSame('Tajná zakázka', $staff['projects'][0]['name']);
        self::assertSame('Tajná zakázka', $staff['revenue_by_project'][0]['project_name']);
    }

    /** Zakázka cizího tenanta není vidět ani přes přímé ID (BOLA). */
    public function testForeignProjectIsNotVisible(): void
    {
        $foreignSupplier = $this->foreignSupplier();
        $foreignClient   = $this->client('Cizí odběratel', true, false, $foreignSupplier);
        $foreignProject  = $this->project($foreignClient, 'Cizí akce', 'CIZI-01');

        self::assertNull($this->profit->detail($this->supplierId, $foreignProject));
    }

    /** Datový filtr ořízne doklady mimo rozsah. */
    public function testDateRangeFiltersDocuments(): void
    {
        $organizer = $this->client('Pořadatel s.r.o.', true, false);
        $projectId = $this->project($organizer, 'Akce přes rok', 'AKCE-06');
        $vendor    = $this->client('Dodavatel s.r.o.', false, true);

        $this->postPurchase($this->purchase('LEDEN-1', $vendor, 1_000.00, 210.00, $projectId, self::YEAR . '-01-10'));
        $this->postPurchase($this->purchase('CERVEN-1', $vendor, 2_000.00, 420.00, $projectId, self::YEAR . '-06-10'));

        $filtered = $this->profit->detail($this->supplierId, $projectId, [
            'date_from' => self::YEAR . '-05-01',
            'date_to'   => self::YEAR . '-12-31',
        ]);
        self::assertEqualsWithDelta(2_000.00, $filtered['cost'], 0.01, 'Lednový náklad je mimo rozsah.');
        self::assertCount(1, $filtered['documents']);
    }

    // ── seed helpers ──────────────────────────────────────────────────────────

    private function postSale(int $invoiceId): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $invoiceId,
            $this->posting->buildFromInvoice($this->supplierId, $invoiceId),
            ['entry_date' => $this->issueDateOf('invoices', $invoiceId), 'posted_by' => $this->userId],
        );
    }

    private function postPurchase(int $purchaseId): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $purchaseId,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId),
            ['entry_date' => $this->issueDateOf('purchase_invoices', $purchaseId), 'posted_by' => $this->userId],
        );
    }

    private function issueDateOf(string $table, int $id): string
    {
        $stmt = $this->db->pdo()->prepare("SELECT issue_date FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    }

    private function foreignSupplier(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO supplier (company_name, street, city, zip, country_id, email,
                                             default_currency_id, default_vat_rate_id, accounting_mode)
                       VALUES (?, "Cizi 1", "Brno", "60200", ?, ?, ?, ?, "double_entry")')
            ->execute(['Cizí firma s.r.o.', $this->czId, 'cizi@example.com', $this->currencyId, $this->vatRateId]);
        return (int) $pdo->lastInsertId();
    }

    private function client(string $name, bool $customer, bool $vendor, ?int $supplierId = null): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId ?? $this->supplierId, $name, $this->czId, $this->currencyId,
            $customer ? 1 : 0, $vendor ? 1 : 0,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function project(int $clientId, string $name, string $number): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO projects (client_id, name, project_number, currency_id, status)
             VALUES (?, ?, ?, ?, "active")'
        )->execute([$clientId, $name, $number, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function sale(string $varsymbol, int $clientId, float $base, float $vat, ?int $projectId): int
    {
        $issue = self::YEAR . '-06-15';
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, project_id, issue_date, tax_date,
                 due_date, currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", "1", ?)'
        )->execute([
            $this->supplierId, $varsymbol, $clientId, $projectId, $issue, $issue, $issue,
            $this->currencyId, $base, $vat, $base + $vat, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('invoice_items', 'invoice_id', $id, $base, $vat);
        return $id;
    }

    private function purchase(
        string $number,
        int $vendorId,
        float $base,
        float $vat,
        ?int $projectId,
        ?string $issue = null,
    ): int {
        $issue ??= self::YEAR . '-06-15';
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, project_id, issue_date,
                 tax_date, due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, "received", "40", "full", ?)'
        )->execute([
            $this->supplierId, $vendorId, $number, $projectId, $issue, $issue, $issue, $issue,
            $this->currencyId, $base, $vat, $base + $vat, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('purchase_invoice_items', 'purchase_invoice_id', $id, $base, $vat);
        return $id;
    }

    private function insertItem(string $table, string $fk, int $id, float $base, float $vat): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO {$table}
                ({$fk}, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Test položka', 1, 'ks', ?, ?, 21.00, ?, ?, ?, 0)"
        )->execute([$id, $base, $this->vatRateId, $base, $vat, $base + $vat]);
    }
}
