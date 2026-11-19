<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Action\Accounting\OtherItemAction;
use MyInvoice\Action\Accounting\OtherItemScheduleAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\Obligations\OtherItemForecastService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class OtherItemScheduleServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private \Psr\Container\ContainerInterface $container;
    private Connection $db;
    private PDO $pdo;
    private OtherItemService $items;
    private OtherItemScheduleService $schedules;
    private OtherItemForecastService $forecast;
    private int $supplierId;

    public function testAutomaticScheduleRequiresJournalPostingPermission(): void
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([$this->supplierId]);
        $source = $this->items->create($this->supplierId, $this->input(), null);
        $this->items->post($this->supplierId, (int) $source['id'], null);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $action = new OtherItemScheduleAction($this->schedules, $this->items);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true, ['other_items' => 2], 'custom'))
            ->withParsedBody(['frequency' => 'monthly', 'auto_post' => true]);
        $response = $action->create($request, new Response(), ['item_id' => $source['id']]);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->schedules->list($this->supplierId));
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly'], null);
        $response = $action->status($request->withParsedBody(['status' => 'active', 'auto_post' => true]), new Response(), ['id' => $schedule['id']]);
        self::assertSame(403, $response->getStatusCode());
        $this->schedules->setStatus($this->supplierId, (int) $schedule['id'], 'active', true);
        $response = $action->generate($request->withParsedBody(['through' => '2099-02-28']), new Response(), ['id' => $schedule['id']]);
        self::assertSame(403, $response->getStatusCode());
        self::assertCount(1, $this->schedules->get($this->supplierId, (int) $schedule['id'])['occurrences']);
        $response = $action->status($request->withParsedBody(['status' => 'paused']), new Response(), ['id' => $schedule['id']]);
        self::assertSame(200, $response->getStatusCode());
        $response = $action->status($request->withParsedBody(['status' => 'active']), new Response(), ['id' => $schedule['id']]);
        self::assertSame(403, $response->getStatusCode());
        $authorized = $request->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true,
            ['other_items' => 2, 'accounting.journal.post' => 2], 'custom'));
        self::assertSame(200, $action->status($authorized->withParsedBody(['status' => 'active']), new Response(), ['id' => $schedule['id']])->getStatusCode());
        self::assertSame(200, $action->status($request->withParsedBody(['status' => 'active', 'auto_post' => false]), new Response(), ['id' => $schedule['id']])->getStatusCode());
    }

    /**
     * API token má k ostatním položkám zápisovou výjimku jen pro koncepty
     * (ApiScopeMiddleware::BEARER_WRITE_EXCEPTIONS). Automatické účtování
     * opakování nesmí zapnout ani obnovit ani s plným oprávněním účtovat, a koncept
     * z automaticky účtovaného opakování nesmí upravit: cron by ho zaúčtoval.
     */
    public function testBearerCannotEnableAutoPostOrEditAutoPostedDraft(): void
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([$this->supplierId]);
        $source = $this->items->create($this->supplierId, $this->input(), null);
        $this->items->post($this->supplierId, (int) $source['id'], null);
        $action = new OtherItemScheduleAction($this->schedules, $this->items);
        $itemAction = $this->container->get(OtherItemAction::class);
        $bearer = (new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true,
                ['other_items' => 2, 'accounting.journal.post' => 2], 'custom'));
        $code = static fn ($response): ?string => json_decode((string) $response->getBody(), true)['error']['code'] ?? null;

        $response = $action->create($bearer->withParsedBody(['frequency' => 'monthly', 'auto_post' => true]),
            new Response(), ['item_id' => $source['id']]);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('other_items.error.auto_post_session_only', $code($response));
        self::assertSame([], $this->schedules->list($this->supplierId));

        $response = $action->create($bearer->withParsedBody(['frequency' => 'monthly', 'auto_post' => false]),
            new Response(), ['item_id' => $source['id']]);
        self::assertSame(201, $response->getStatusCode());
        $scheduleId = (int) json_decode((string) $response->getBody(), true)['id'];

        $response = $action->status($bearer->withParsedBody(['status' => 'active', 'auto_post' => true]),
            new Response(), ['id' => $scheduleId]);
        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->schedules->get($this->supplierId, $scheduleId)['auto_post']);

        $generated = $this->schedules->generate($this->supplierId, $scheduleId, '2099-02-28', null);
        $draftId = $generated['created_ids'][0];
        $this->schedules->setStatus($this->supplierId, $scheduleId, 'paused', true);

        $response = $action->status($bearer->withParsedBody(['status' => 'active']), new Response(), ['id' => $scheduleId]);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('paused', $this->schedules->get($this->supplierId, $scheduleId)['status']);
        $response = $action->generate($bearer->withParsedBody(['through' => '2099-03-31']), new Response(), ['id' => $scheduleId]);
        self::assertSame(403, $response->getStatusCode());

        $update = $this->input(['due_on' => '2099-03-10', 'issued_on' => '2099-02-28']);
        $response = $itemAction->update($bearer->withParsedBody($update), new Response(), ['id' => (string) $draftId]);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('other_items.error.auto_post_session_only', $code($response));
        self::assertSame('2099-03-05', $this->items->get($this->supplierId, $draftId)['due_on']);

        $plain = $this->items->create($this->supplierId, $this->input(), null);
        $response = $itemAction->update($bearer->withParsedBody($this->input(['title' => 'Upravený koncept'])),
            new Response(), ['id' => (string) $plain['id']]);
        self::assertSame(200, $response->getStatusCode());

        $response = $action->status($bearer->withParsedBody(['status' => 'active', 'auto_post' => false]),
            new Response(), ['id' => $scheduleId]);
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->schedules->get($this->supplierId, $scheduleId)['auto_post']);
        self::assertSame('active', $this->schedules->get($this->supplierId, $scheduleId)['status']);
    }

    /**
     * Smazáním konceptu z automaticky účtovaného opakování by token potlačil jeho
     * zaúčtování, proto platí stejný guard jako u úpravy. Webové rozhraní ho
     * smaže, koncept bez automatiky smaže i token.
     */
    public function testBearerCannotDeleteAutoPostedDraft(): void
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([$this->supplierId]);
        $source = $this->items->create($this->supplierId, $this->input(), null);
        $this->items->post($this->supplierId, (int) $source['id'], null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly'], null);
        $scheduleId = (int) $schedule['id'];
        $draftIds = $this->schedules->generate($this->supplierId, $scheduleId, '2099-03-31', null)['created_ids'];
        self::assertCount(2, $draftIds);
        $this->schedules->setStatus($this->supplierId, $scheduleId, 'active', true);

        $itemAction = $this->container->get(OtherItemAction::class);
        $role = new EffectiveRole(0, 'Test', 'staff', true, ['other_items' => 2, 'accounting.journal.post' => 2], 'custom');
        $request = (new ServerRequestFactory())->createServerRequest('DELETE', '/')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute('auth.effective_role', $role);
        $bearer = $request->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer');
        $session = $request->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');

        $response = $itemAction->delete($bearer, new Response(), ['id' => (string) $draftIds[0]]);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('other_items.error.auto_post_session_only',
            json_decode((string) $response->getBody(), true)['error']['code'] ?? null);
        self::assertSame('draft', $this->items->get($this->supplierId, $draftIds[0])['status']);

        self::assertSame(200, $itemAction->delete($session, new Response(), ['id' => (string) $draftIds[0]])->getStatusCode());

        $plain = $this->items->create($this->supplierId, $this->input(), null);
        self::assertSame(200, $itemAction->delete($bearer, new Response(), ['id' => (string) $plain['id']])->getStatusCode());

        // Vypnout automatiku token smí; pak už koncept smaže i on.
        $this->schedules->setStatus($this->supplierId, $scheduleId, 'active', false);
        self::assertSame(200, $itemAction->delete($bearer, new Response(), ['id' => (string) $draftIds[1]])->getStatusCode());
    }

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->container = $container;
        $this->db = $container->get(Connection::class);
        $this->items = $container->get(OtherItemService::class);
        $this->schedules = $container->get(OtherItemScheduleService::class);
        $this->forecast = $container->get(OtherItemForecastService::class);
        $this->pdo = $this->db->pdo();
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testMonthlyGenerationClampsDayAndNeverDuplicatesOrPosts(): void
    {
        $first = $this->items->create($this->supplierId, $this->input(), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $first['id'], ['frequency' => 'monthly'], null);
        self::assertCount(1, $schedule['occurrences']);
        self::assertSame('2099-01-31', $schedule['occurrences'][0]['issued_on']);
        self::assertSame('draft', $schedule['occurrences'][0]['status']);
        $generated = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-03-31', null);
        self::assertCount(2, $generated['created_ids']);
        self::assertSame(['2099-02-28', '2099-03-31'], array_column(array_slice($generated['schedule']['occurrences'], 1), 'issued_on'));
        self::assertSame([], $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-03-31', null)['created_ids']);
        $dates = [];
        foreach ($generated['created_ids'] as $id) {
            $item = $this->items->get($this->supplierId, $id);
            $dates[] = [$item['issued_on'], $item['due_on']];
            self::assertSame('draft', $item['status']);
            self::assertNull($item['journal_entry_id']);
        }
        self::assertSame([['2099-02-28', '2099-03-05'], ['2099-03-31', '2099-04-05']], $dates);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM other_item_schedule_occurrences WHERE supplier_id = ? AND schedule_id = ?');
        $stmt->execute([$this->supplierId, $schedule['id']]);
        self::assertSame(3, (int) $stmt->fetchColumn());
    }

    public function testListFiltersBySourceItem(): void
    {
        $first = $this->items->create($this->supplierId, $this->input(), null);
        $second = $this->items->create($this->supplierId, $this->input(), null);
        $firstSchedule = $this->schedules->create($this->supplierId, (int) $first['id'], ['frequency' => 'monthly'], null);
        $secondSchedule = $this->schedules->create($this->supplierId, (int) $second['id'], ['frequency' => 'monthly'], null);

        self::assertSame([(int) $secondSchedule['id']],
            array_map('intval', array_column($this->schedules->list($this->supplierId, (int) $second['id']), 'id')));
        self::assertSame([(int) $secondSchedule['id'], (int) $firstSchedule['id']],
            array_map('intval', array_column($this->schedules->list($this->supplierId), 'id')), 'Bez filtru všechny plány firmy.');

        $action = new OtherItemScheduleAction($this->schedules, $this->items);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true, ['other_items' => 1], 'custom'));
        $response = $action->list($request->withQueryParams(['source_item_id' => (string) $first['id']]), new Response());
        self::assertSame(200, $response->getStatusCode());
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([(int) $firstSchedule['id']], array_map('intval', array_column($body['data']['items'] ?? $body['items'], 'id')));
        foreach (['0', 'abc', '-3'] as $invalid) {
            self::assertSame(422, $action->list($request->withQueryParams(['source_item_id' => $invalid]), new Response())->getStatusCode(), $invalid);
        }
    }

    public function testRecurringDraftCopiesSplitPostingLinesWithoutPosting(): void
    {
        $first = $this->items->create($this->supplierId, $this->input([
            'counter_account_code' => null,
            'posting_lines' => [
                ['account_code' => '518', 'amount' => 700],
                ['account_code' => '378', 'amount' => 500],
            ],
        ]), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $first['id'], ['frequency' => 'monthly'], null);
        $created = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-02-28', null)['created_ids'];
        self::assertCount(1, $created);
        $next = $this->items->get($this->supplierId, $created[0]);
        self::assertSame('draft', $next['status']);
        self::assertNull($next['counter_account_code']);
        self::assertSame($first['posting_lines'], $next['posting_lines']);
        self::assertNull($next['journal_entry_id']);
    }

    public function testTenantBoundaryAndPauseBlockGeneration(): void
    {
        $first = $this->items->create($this->supplierId, $this->input(), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $first['id'], ['frequency' => 'quarterly'], null);
        try {
            $this->schedules->generate($this->supplierId + 100000, (int) $schedule['id'], '2099-12-31', null);
            self::fail('Cizí rozvrh nesmí být dostupný.');
        } catch (OtherItemException $e) {
            self::assertSame('schedule_not_found', $e->errorCode);
        }
        $this->schedules->setStatus($this->supplierId, (int) $schedule['id'], 'paused');
        try {
            $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-12-31', null);
            self::fail('Pozastavený rozvrh nesmí generovat.');
        } catch (OtherItemException $e) {
            self::assertSame('schedule_paused', $e->errorCode);
        }
    }

    public function testCancelledSourceStopsRecurrenceAndCannotBeResumed(): void
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([$this->supplierId]);
        $first = $this->items->create($this->supplierId, $this->input(), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $first['id'], ['frequency' => 'monthly'], null);
        $this->items->post($this->supplierId, (int) $first['id'], null);
        $this->items->reverse($this->supplierId, (int) $first['id'], 'Ukončená smlouva', null);

        self::assertSame('paused', $this->schedules->get($this->supplierId, (int) $schedule['id'])['status']);
        self::assertSame('paused', $this->schedules->list($this->supplierId)[0]['status']);
        $this->pdo->prepare("UPDATE other_item_schedules SET status = 'active' WHERE supplier_id = ? AND id = ?")
            ->execute([$this->supplierId, $schedule['id']]);
        self::assertSame('paused', $this->schedules->get($this->supplierId, (int) $schedule['id'])['status']);
        self::assertSame('paused', $this->schedules->list($this->supplierId)[0]['status']);
        foreach (['generate', 'resume'] as $action) {
            try {
                if ($action === 'generate') {
                    $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-03-31', null);
                } else {
                    $this->schedules->setStatus($this->supplierId, (int) $schedule['id'], 'active');
                }
                self::fail('Stornovaný zdroj nesmí pokračovat v opakování.');
            } catch (OtherItemException $e) {
                self::assertContains($e->errorCode, ['schedule_paused', 'schedule_source_inactive']);
            }
        }
        self::assertCount(1, $this->schedules->get($this->supplierId, (int) $schedule['id'])['occurrences']);
    }

    public function testReversedPostedSourcePausesRecurrence(): void
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplierId);
        (new AccountingPeriodRepository($this->db))->create($this->supplierId, 2099, '2099-01-01', '2099-12-31');
        $first = $this->items->create($this->supplierId, $this->input(), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $first['id'], ['frequency' => 'monthly'], null);
        $this->items->post($this->supplierId, (int) $first['id'], null);
        self::assertSame('reversed', $this->items->reverse($this->supplierId, (int) $first['id'], 'Ukončená smlouva', null)['status']);
        self::assertSame('paused', $this->schedules->get($this->supplierId, (int) $schedule['id'])['status']);
    }

    public function testInstallmentsReplaceSingleCashflowWithoutDuplicatingResult(): void
    {
        $first = $this->items->create($this->supplierId, $this->input(), null);
        $plan = $this->schedules->setInstallments($this->supplierId, (int) $first['id'], [
            ['due_on' => '2099-02-05', 'amount' => 500],
            ['due_on' => '2099-03-05', 'amount' => 700],
        ]);
        self::assertCount(2, $plan);
        $forecast = $this->forecast->dueBetween($this->supplierId, '2099-01-01', '2099-03-31');
        self::assertSame(['2099-02-05', '2099-03-05'], array_column($forecast, 'due_on'));
        self::assertSame([500.0, 700.0], array_column($forecast, 'remaining'));
        self::assertSame(1200.0, array_sum(array_column($forecast, 'remaining')));
        try {
            $this->items->update($this->supplierId, (int) $first['id'], $this->input(['amount' => 1300]), null);
            self::fail('Změna částky nesmí rozbít rozvrh splátek.');
        } catch (OtherItemException $e) {
            self::assertSame('has_installments', $e->errorCode);
        }
        self::assertSame([], $this->schedules->setInstallments($this->supplierId, (int) $first['id'], []));
        $original = $this->forecast->dueBetween($this->supplierId, '2099-01-01', '2099-03-31');
        self::assertCount(1, $original);
        self::assertSame('2099-02-05', $original[0]['due_on']);
        self::assertSame(1200.0, $original[0]['remaining']);
    }

    public function testDraftWithInstallmentsAllowsUnrelatedEdits(): void
    {
        $first = $this->items->create($this->supplierId, $this->input(), null);
        $this->schedules->setInstallments($this->supplierId, (int) $first['id'], [
            ['due_on' => '2099-02-05', 'amount' => 500],
            ['due_on' => '2099-03-05', 'amount' => 700],
        ]);
        $updated = $this->items->update($this->supplierId, (int) $first['id'],
            $this->input(['title' => 'Upravené nájemné', 'due_on' => '2099-02-06']), null);
        self::assertSame('Upravené nájemné', $updated['title']);
        self::assertCount(2, $this->schedules->installments($this->supplierId, (int) $first['id']));
        try {
            $this->items->update($this->supplierId, (int) $first['id'],
                $this->input(['issued_on' => '2099-02-06']), null);
            self::fail('Datum vzniku po první splátce nesmí projít.');
        } catch (OtherItemException $e) {
            self::assertSame('installment_date', $e->errorCode);
        }
    }

    public function testGeneratedItemKeepsSourceContractAndSourceCannotBeDeleted(): void
    {
        $first = $this->items->create($this->supplierId, $this->input(), null);
        $userId = (int) $this->pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $documents = new DocumentRepository($this->db);
        $documentId = $documents->insert([
            'supplier_id' => $this->supplierId, 'folder_id' => null,
            'title' => 'Syntetická nájemní smlouva', 'description' => null,
            'original_name' => 'smlouva.pdf', 'filename' => str_repeat('e', 64),
            'sha256' => str_repeat('e', 64), 'mime_type' => 'application/pdf',
            'size_bytes' => 100, 'doc_type' => 'pdf', 'uploaded_by' => $userId,
            'scope' => 'company', 'owner_user_id' => null,
        ]);
        $this->pdo->prepare("INSERT INTO document_links (document_id, supplier_id, entity_type, entity_id)
            VALUES (?, ?, 'other_item', ?)")->execute([$documentId, $this->supplierId, $first['id']]);

        $schedule = $this->schedules->create($this->supplierId, (int) $first['id'], ['frequency' => 'monthly'], null);
        try {
            $this->items->deleteDraft($this->supplierId, (int) $first['id']);
            self::fail('Zdroj aktivního rozvrhu nesmí zmizet.');
        } catch (OtherItemException $e) {
            self::assertSame('has_schedule', $e->errorCode);
        }
        $generated = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-02-28', null);
        $linked = $this->pdo->prepare("SELECT COUNT(*) FROM document_links
            WHERE supplier_id = ? AND document_id = ? AND entity_type = 'other_item' AND entity_id = ?");
        $linked->execute([$this->supplierId, $documentId, $generated['created_ids'][0]]);
        self::assertSame(1, (int) $linked->fetchColumn());
        $this->items->deleteDraft($this->supplierId, $generated['created_ids'][0]);
        self::assertSame('cancelled', $this->items->get($this->supplierId, $generated['created_ids'][0])['status']);
        self::assertSame([], $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-02-28', null)['created_ids']);
    }

    public function testDeletedScheduleKeepsGeneratedItemsAndReleasesSource(): void
    {
        $first = $this->items->create($this->supplierId, $this->input(), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $first['id'], ['frequency' => 'monthly'], null);
        $created = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-02-28', null)['created_ids'];
        try {
            $this->schedules->delete($this->supplierId + 100000, (int) $schedule['id']);
            self::fail('Cizí rozvrh nesmí jít smazat.');
        } catch (OtherItemException $e) {
            self::assertSame('schedule_not_found', $e->errorCode);
        }
        $this->schedules->delete($this->supplierId, (int) $schedule['id']);
        self::assertSame([], $this->schedules->list($this->supplierId));
        self::assertSame('draft', $this->items->get($this->supplierId, $created[0])['status']);
        $this->items->deleteDraft($this->supplierId, (int) $first['id']);
        $again = $this->schedules->create($this->supplierId, $created[0], ['frequency' => 'monthly'], null);
        self::assertCount(1, $again['occurrences']);
    }

    public function testPaidAmountSettlesOldestInstallmentFirst(): void
    {
        $first = $this->items->create($this->supplierId, $this->input(), null);
        $this->schedules->setInstallments($this->supplierId, (int) $first['id'], [
            ['due_on' => '2099-02-05', 'amount' => 500],
            ['due_on' => '2099-03-05', 'amount' => 700],
        ]);
        $hash = hash('sha256', 'synthetic-other-item-' . $this->supplierId);
        $this->pdo->prepare('INSERT INTO bank_statements
            (supplier_id, file_name, file_hash, account_number, bank_code, currency,
             statement_date, prev_balance, curr_balance, credit_total, debit_total, transaction_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, 600, 600, 0, 1)')
            ->execute([$this->supplierId, 'synteticky-vypis.gpc', $hash,
                '1000000005/0100', '0100', 'CZK', '2099-02-06']);
        $statementId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO bank_transactions (statement_id, posted_at, amount, currency)
            VALUES (?, ?, 600, ?)')->execute([$statementId, '2099-02-06', 'CZK']);
        $transactionId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO other_item_allocations
            (supplier_id, other_item_id, bank_transaction_id, amount, payment_on)
            VALUES (?, ?, ?, 600, ?)')->execute([$this->supplierId, $first['id'], $transactionId, '2099-02-06']);

        $remaining = $this->forecast->dueBetween($this->supplierId, '2099-01-01', '2099-03-31');
        self::assertCount(1, $remaining);
        self::assertSame('2099-03-05', $remaining[0]['due_on']);
        self::assertSame(600.0, $remaining[0]['remaining']);
    }

    public function testAutomaticPostingUsesIssueDateAndNeverPostsFutureOrDuplicates(): void
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplierId);
        $anchor = new \DateTimeImmutable('first day of last month');
        $future = new \DateTimeImmutable('first day of next month');
        $periods = new AccountingPeriodRepository($this->db);
        foreach (array_unique([(int) $anchor->format('Y'), (int) $future->format('Y')]) as $year) {
            $periods->create($this->supplierId, $year, "$year-01-01", "$year-12-31");
        }
        $source = $this->items->create($this->supplierId, $this->input([
            'issued_on' => $anchor->format('Y-m-d'), 'due_on' => $anchor->modify('+5 days')->format('Y-m-d'),
        ]), null);
        $this->items->post($this->supplierId, (int) $source['id'], null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], [
            'frequency' => 'monthly', 'auto_post' => true,
        ], null);
        $result = $this->schedules->generate($this->supplierId, (int) $schedule['id'], $future->format('Y-m-d'), null);
        self::assertCount(2, $result['created_ids']);
        $current = $this->items->get($this->supplierId, $result['created_ids'][0]);
        self::assertSame('posted', $current['status']);
        self::assertTrue($schedule['auto_post']);
        self::assertSame([$result['created_ids'][0]], $result['posted_ids']);
        $entry = $this->pdo->prepare('SELECT entry_date FROM journal_entries WHERE supplier_id = ? AND id = ?');
        $entry->execute([$this->supplierId, $current['journal_entry_id']]);
        self::assertSame((new \DateTimeImmutable('first day of this month'))->format('Y-m-d'), $entry->fetchColumn());
        self::assertSame('draft', $this->items->get($this->supplierId, $result['created_ids'][1])['status']);
        $again = $this->schedules->generate($this->supplierId, (int) $schedule['id'], $future->format('Y-m-d'), null);
        self::assertSame([], $again['created_ids']);
        self::assertSame([], $again['posted_ids']);
    }

    public function testEnablingAutomaticPostingPicksUpExistingDueDrafts(): void
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([$this->supplierId]);
        $anchor = new \DateTimeImmutable('first day of last month');
        $source = $this->items->create($this->supplierId, $this->input([
            'issued_on' => $anchor->format('Y-m-d'), 'due_on' => $anchor->modify('+5 days')->format('Y-m-d'),
        ]), null);
        $this->items->post($this->supplierId, (int) $source['id'], null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly'], null);
        $through = date('Y-m-d');
        $drafts = $this->schedules->generate($this->supplierId, (int) $schedule['id'], $through, null);
        self::assertSame('draft', $this->items->get($this->supplierId, $drafts['created_ids'][0])['status']);
        $this->schedules->setStatus($this->supplierId, (int) $schedule['id'], 'active', true);
        $result = $this->schedules->generate($this->supplierId, (int) $schedule['id'], $through, null);
        self::assertSame('confirmed', $this->items->get($this->supplierId, $drafts['created_ids'][0])['status']);
        self::assertFalse($drafts['schedule']['auto_post']);
        self::assertSame([], $drafts['posted_ids']);
        self::assertSame([], $result['created_ids']);
        self::assertSame($drafts['created_ids'], $result['posted_ids']);
        self::assertSame('confirmed', $this->items->get($this->supplierId, $result['posted_ids'][0])['status']);
        self::assertTrue($this->schedules->setStatus($this->supplierId, (int) $schedule['id'], 'paused')['auto_post']);
    }

    public function testAutomaticPostingRequiresConfirmedSource(): void
    {
        $source = $this->items->create($this->supplierId, $this->input(), null);
        try {
            $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly', 'auto_post' => true], null);
            self::fail('Nepotvrzená šablona nesmí účtovat automaticky.');
        } catch (OtherItemException $e) {
            self::assertSame('schedule_source_unconfirmed', $e->errorCode);
        }
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly'], null);
        try {
            $this->schedules->setStatus($this->supplierId, (int) $schedule['id'], 'active', true);
            self::fail('Nepotvrzená šablona nesmí zapnout automatiku.');
        } catch (OtherItemException $e) {
            self::assertSame('schedule_source_unconfirmed', $e->errorCode);
        }
    }

    public function testClosedPeriodRejectsAutomaticPostingAndRollsBackGeneration(): void
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplierId);
        $anchor = new \DateTimeImmutable('first day of last month');
        $periods = new AccountingPeriodRepository($this->db);
        foreach (array_unique([(int) $anchor->format('Y'), (int) date('Y')]) as $year) {
            $periodId = $periods->create($this->supplierId, $year, "$year-01-01", "$year-12-31");
        }
        $source = $this->items->create($this->supplierId, $this->input([
            'issued_on' => $anchor->format('Y-m-d'), 'due_on' => $anchor->modify('+5 days')->format('Y-m-d'),
        ]), null);
        $this->items->post($this->supplierId, (int) $source['id'], null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly', 'auto_post' => true], null);
        $periods->setStatus($periodId, $this->supplierId, 'closed');
        try {
            $this->schedules->generate($this->supplierId, (int) $schedule['id'], date('Y-m-d'), null);
            self::fail('Uzavřené období nesmí být obejito.');
        } catch (PostingException $e) {
            self::assertSame('period_not_open', $e->errorCode);
        }
        $after = $this->schedules->get($this->supplierId, (int) $schedule['id']);
        self::assertSame(1, (int) $after['next_index']);
        self::assertCount(1, $after['occurrences']);
    }

    public function testListSortingRunsBeforePaginationWithStableTies(): void
    {
        foreach ([900, 100, 500, 100] as $amount) {
            $this->items->create($this->supplierId, $this->input(['amount' => $amount]), null);
        }
        $first = $this->items->list($this->supplierId, ['sort_by' => 'amount', 'sort_dir' => 'asc'], 1, 2);
        $second = $this->items->list($this->supplierId, ['sort_by' => 'amount', 'sort_dir' => 'asc'], 2, 2);
        self::assertSame([100.0, 100.0], array_map('floatval', array_column($first['items'], 'amount')));
        self::assertSame([500.0, 900.0], array_map('floatval', array_column($second['items'], 'amount')));
        self::assertLessThan((int) $first['items'][1]['id'], (int) $first['items'][0]['id']);
    }

    private function input(array $changes = []): array
    {
        return array_replace([
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Syntetické nájemné',
            'issued_on' => '2099-01-31', 'due_on' => '2099-02-05',
            'currency' => 'CZK', 'amount' => 1200, 'counter_account_code' => '518',
        ], $changes);
    }
}
