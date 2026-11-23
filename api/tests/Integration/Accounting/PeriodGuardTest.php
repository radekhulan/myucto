<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\AccountingPeriodAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\EntityCategoryService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Integrační testy stavového automatu období a zámků (Epic F4, §6.2 I11–I15):
 * matice přechodů R2 přes /status endpoint, reopen guard R3, optimistická
 * konkurence row_version (R4), překryvy/díry období (R5) a R7 flag
 * allow_closing_period v PostingService.
 *
 * Izolovaný supplier, transakce s rollbackem v tearDown, soft-skip bez cfg.php.
 */
#[Group('integration')]
final class PeriodGuardTest extends TestCase
{
    private const YEAR = 2098;
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private AccountingPeriodAction $action;
    private AccountingPeriodRepository $periods;
    private ClosingService $closing;
    private PostingService $posting;
    private JournalEntryRepository $journal;

    private EntityCategoryService $categories;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
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
            $this->action  = $container->get(AccountingPeriodAction::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->closing = $container->get(ClosingService::class);
            $this->posting = $container->get(PostingService::class);
            $this->journal = $container->get(JournalEntryRepository::class);
            $this->categories = $container->get(EntityCategoryService::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId   = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId    = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId         = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "f4-guard@example.com", ?, ?, "double_entry")'
        );
        $stmt->execute(['F4 guard test s.r.o.', $czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::ENDS_ON);
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

    // ── I11: matice přechodů (R2, §2.4) ──────────────────────────────────────

    public function testI11WorkflowTransitionsRefusedViaStatusEndpoint(): void
    {
        // open↔closing a closing→closed patří výhradně workflow
        foreach ([
            ['open', 'closing'],
            ['closing', 'open'],
            ['closing', 'closed'],
        ] as [$from, $to]) {
            $this->setStatusRaw($from);
            $res = $this->postStatus(['status' => $to, 'row_version' => $this->rv()]);
            self::assertSame(422, $res['status'], "{$from}→{$to} přes /status je zakázán.");
            self::assertSame('use_closing_workflow', $res['body']['error']['code']);
        }
    }

    public function testI11InvalidTransitionsRefused(): void
    {
        foreach ([
            ['open', 'closed'],
            ['open', 'approved'],
            ['open', 'reviewed'],
            ['closed', 'closing'],
            ['reviewed', 'open'],
        ] as [$from, $to]) {
            $this->setStatusRaw($from);
            $res = $this->postStatus(['status' => $to, 'row_version' => $this->rv(), 'confirm' => true, 'reason' => 'testovací důvod dlouhý']);
            self::assertSame(422, $res['status'], "{$from}→{$to} není povolen.");
            self::assertSame('invalid_status_transition', $res['body']['error']['code']);
        }
    }

    /**
     * EP-5 (§17/7 ZoÚ): zákonné schválení je NEVRATNÉ — ze stavu 'approved' nevede
     * žádný přechod stavu ven a API jej odmítne kódem approval_is_final. Původní
     * přechod 'approved→closed' (zrušení schválení) byl odstraněn.
     */
    public function testApprovedIsFinalNoTransitionOut(): void
    {
        foreach (['closed', 'open', 'closing', 'reviewed'] as $to) {
            $this->setStatusRaw('approved');
            $res = $this->postStatus(['status' => $to, 'row_version' => $this->rv(), 'confirm' => true, 'reason' => 'pokus o zrušení schválení']);
            self::assertSame(422, $res['status'], "approved→{$to} musí být odmítnut.");
            self::assertSame('approval_is_final', $res['body']['error']['code'], "approved→{$to} = approval_is_final.");
        }
    }

    public function testI11ApproveRequiresConfirm(): void
    {
        // closed→approved bez confirm → 422
        $this->setStatusRaw('closed');
        $res = $this->postStatus(['status' => 'approved', 'row_version' => $this->rv()]);
        self::assertSame(422, $res['status'], 'Schválení vyžaduje confirm=true (§17/7).');
        self::assertSame('validation_failed', $res['body']['error']['code']);

        // s confirm → 200 approved (+ approved_at/by)
        $res = $this->postStatus(['status' => 'approved', 'row_version' => $this->rv(), 'confirm' => true]);
        self::assertSame(200, $res['status']);
        self::assertSame('approved', $res['body']['status']);
        self::assertNotNull($res['body']['approved_at']);
        self::assertSame($this->userId, (int) $res['body']['approved_by']);
    }

    /**
     * Zmražení automatických sekcí přílohy k závěrce při uzavření knih mohlo selhat
     * (varování kroku close_books). Schválení ho stejně jako u kategorie ÚJ zopakuje,
     * a když ani teď nejde, schválení odmítne. Po nápravě podkladů projde a příloha
     * nese vyhodnocenou kategorii.
     */
    public function testApprovalRetriesStatementNotesFreezeAndBlocksWhenItFails(): void
    {
        $this->setStatusRaw('closed');
        // Kategorie ÚJ je zmražená z uzavření knih, příloha ne.
        $this->categories->freeze($this->supplierId, $this->periodId);
        $pdo = $this->db->pdo();
        $versions = $pdo->query("SELECT id, valid_from FROM statement_versions WHERE statement_type = 'balance_sheet' AND framework = 'cz_500'")
            ->fetchAll(\PDO::FETCH_KEY_PAIR);
        $pdo->exec("UPDATE statement_versions SET valid_from = '2999-01-01' WHERE statement_type = 'balance_sheet' AND framework = 'cz_500'");

        $res = $this->postStatus(['status' => 'approved', 'row_version' => $this->rv(), 'confirm' => true]);

        self::assertSame(422, $res['status'], (string) json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('statement_notes_not_frozen', $res['body']['error']['code']);
        self::assertSame('closed', $this->periods->findById($this->supplierId, $this->periodId)['status']);

        $restore = $pdo->prepare('UPDATE statement_versions SET valid_from = ? WHERE id = ?');
        foreach ($versions as $id => $validFrom) {
            $restore->execute([$validFrom, $id]);
        }
        $res = $this->postStatus(['status' => 'approved', 'row_version' => $this->rv(), 'confirm' => true]);

        self::assertSame(200, $res['status'], (string) json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $stored = $pdo->prepare("SELECT content FROM statement_notes WHERE supplier_id = ? AND fiscal_year = ? AND section_key = 'entity_category'");
        $stored->execute([$this->supplierId, self::YEAR]);
        self::assertNotFalse($stored->fetchColumn(), 'Schválení dozmrazí sekci s kategorií.');
    }

    /**
     * EP-5: VRATNÁ interní kontrola ('reviewed') je oddělená od NEVRATNÉHO zákonného
     * schválení. Přechod, který dřív „rušil schválení" (approved→closed), ruší nově
     * jen interní kontrolu (reviewed→closed) a schválená data se ho netýkají.
     */
    public function testReviewedIsReversibleAndDistinctFromApproved(): void
    {
        // closed→reviewed (vratná interní kontrola) → 200, reviewed_at/by nastaveno,
        // approved_* zůstávají prázdné (nejde o zákonné schválení).
        $this->setStatusRaw('closed');
        $res = $this->postStatus(['status' => 'reviewed', 'row_version' => $this->rv()]);
        self::assertSame(200, $res['status']);
        self::assertSame('reviewed', $res['body']['status']);
        self::assertNotNull($res['body']['reviewed_at'], 'reviewed_at se při interní kontrole plní.');
        self::assertSame($this->userId, (int) $res['body']['reviewed_by']);
        self::assertNull($res['body']['approved_at'], 'Interní kontrola NENÍ zákonné schválení.');
        self::assertNull($res['body']['approved_by']);

        // reviewed→closed bez reason → 422 (auditní stopa); s reason → 200, reviewed_* se čistí.
        $res = $this->postStatus(['status' => 'closed', 'row_version' => $this->rv()]);
        self::assertSame(422, $res['status'], 'Zrušení interní kontroly vyžaduje reason (min. 10 znaků).');
        self::assertSame('validation_failed', $res['body']['error']['code']);
        $res = $this->postStatus(['status' => 'closed', 'row_version' => $this->rv(), 'reason' => 'interní kontrola vrácena k doplnění']);
        self::assertSame(200, $res['status']);
        self::assertSame('closed', $res['body']['status']);
        self::assertNull($res['body']['reviewed_at'], 'reviewed_at se při návratu na closed vyčistí (vratné).');
        self::assertNull($res['body']['reviewed_by']);
    }

    /**
     * EP-5: pole zákonného schválení (orgán, odkaz na rozhodnutí, hash dokumentu)
     * se při schválení uloží a schválení je NEVRATNÉ — data přetrvají a nelze je
     * přechodem stavu vymazat.
     */
    public function testApprovalMetadataPersistsAndSurvivesTransitionAttempts(): void
    {
        // reviewed→approved (schválení lze provést i z interní kontroly), s metadaty.
        $this->setStatusRaw('reviewed');
        $res = $this->postStatus([
            'status' => 'approved',
            'row_version' => $this->rv(),
            'confirm' => true,
            'approval_body' => 'Valná hromada společnosti',
            'approval_decision_ref' => 'Zápis VH č. 1/2099',
            'approval_document_hash' => str_repeat('a', 64),
        ]);
        self::assertSame(200, $res['status']);
        self::assertSame('approved', $res['body']['status']);
        self::assertNotNull($res['body']['approved_at']);
        self::assertSame($this->userId, (int) $res['body']['approved_by']);
        self::assertSame('Valná hromada společnosti', $res['body']['approval_body']);
        self::assertSame('Zápis VH č. 1/2099', $res['body']['approval_decision_ref']);
        self::assertSame(str_repeat('a', 64), $res['body']['approval_document_hash']);

        $approvedAt = $res['body']['approved_at'];

        // Pokus o zrušení schválení přechodem stavu → 422 approval_is_final, data beze změny.
        $res = $this->postStatus(['status' => 'closed', 'row_version' => $this->rv(), 'confirm' => true, 'reason' => 'omylem schváleno adminem']);
        self::assertSame(422, $res['status']);
        self::assertSame('approval_is_final', $res['body']['error']['code']);

        $after = $this->periods->findById($this->supplierId, $this->periodId);
        self::assertSame('approved', (string) $after['status'], 'Období zůstává schválené.');
        self::assertSame($approvedAt, $after['approved_at'], 'approved_at se NIKDY nemaže (§17/7).');
        self::assertSame('Valná hromada společnosti', (string) $after['approval_body']);
        self::assertSame('Zápis VH č. 1/2099', (string) $after['approval_decision_ref']);
        self::assertSame(str_repeat('a', 64), (string) $after['approval_document_hash']);
    }

    public function testI11SameStatusIsNoOp(): void
    {
        $res = $this->postStatus(['status' => 'open']);
        self::assertSame(200, $res['status'], 'Same-status = no-op (bez row_version).');
        self::assertSame('open', $res['body']['status']);
    }

    // ── I12: reopen guard (R3) + audit ───────────────────────────────────────

    public function testI12ReopenBlockedByClosingEntriesThenAllowedAfterRevert(): void
    {
        $this->seedRevenue();
        $this->runChainToClosed();

        // closed→open s existujícím closing zápisem → 422 closing_entries_exist
        $res = $this->postStatus(['status' => 'open', 'row_version' => $this->rv(), 'reason' => 'oprava zaúčtování prosinec']);
        self::assertSame(422, $res['status']);
        self::assertSame('closing_entries_exist', $res['body']['error']['code']);

        // reason je povinný i po revertu
        $this->closing->revertStep($this->supplierId, $this->periodId, 'close_books', $this->rv(), $this->meta());
        $this->setStatusRaw('closed'); // revert vrátil closing; guard testujeme z closed
        $res = $this->postStatus(['status' => 'open', 'row_version' => $this->rv(), 'reason' => 'krátký']);
        self::assertSame(422, $res['status'], 'reason < 10 znaků → validation_failed.');
        self::assertSame('validation_failed', $res['body']['error']['code']);

        // po revertu (closing zápis smazán) reopen projde + audit s reason
        $res = $this->postStatus(['status' => 'open', 'row_version' => $this->rv(), 'reason' => 'oprava zaúčtování prosinec']);
        self::assertSame(200, $res['status']);
        self::assertSame('open', $res['body']['status']);

        $stmt = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log
              WHERE supplier_id = ? AND action = 'accounting.period_reopened' AND entity_id = ?
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$this->supplierId, $this->periodId]);
        $payload = $stmt->fetchColumn();
        self::assertNotFalse($payload, 'Reopen je auditovaný (accounting.period_reopened).');
        self::assertStringContainsString('oprava zaúčtování prosinec', (string) $payload, 'Audit nese reason (§17/7).');
    }

    // ── I13: optimistická konkurence (R4) ────────────────────────────────────

    public function testI13StaleRowVersionGets409(): void
    {
        $this->setStatusRaw('closed');
        $staleVersion = $this->rv();

        // První klient: closed→reviewed (vratná interní kontrola) projde a bumpne verzi.
        $ok = $this->postStatus(['status' => 'reviewed', 'row_version' => $staleVersion]);
        self::assertSame(200, $ok['status'], 'První klient projde.');

        // Druhý klient se starou verzí (reviewed→closed je platný přechod) → 409 version_conflict.
        $conflict = $this->postStatus(['status' => 'closed', 'row_version' => $staleVersion, 'reason' => 'druhý admin současně']);
        self::assertSame(409, $conflict['status']);
        self::assertSame('version_conflict', $conflict['body']['error']['code']);
    }

    // ── I14: překryv a díra v řadě období (R5) ───────────────────────────────

    public function testI14OverlappingPeriodRefused(): void
    {
        $res = $this->call('create', [], [
            'fiscal_year' => self::YEAR + 1,
            'starts_on'   => self::YEAR . '-07-01', // překrývá 2098
            'ends_on'     => (self::YEAR + 1) . '-06-30',
        ]);
        self::assertSame(422, $res['status']);
        self::assertSame('period_overlap', $res['body']['error']['code']);
    }

    public function testI14OpenNextWithGapRefused(): void
    {
        // Pozdější období 2100 nenavazuje na 2098 (chybí 2099) → period_gap
        $this->periods->create($this->supplierId, self::YEAR + 2, (self::YEAR + 2) . '-01-01', (self::YEAR + 2) . '-12-31');
        $this->runChainToClosed(); // prázdné období se uzavře bez zápisu

        try {
            $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Očekávána ClosingException period_gap.');
        } catch (ClosingException $e) {
            self::assertSame('period_gap', $e->errorCode);
        }
    }

    // ── I15: R7 flag allow_closing_period ────────────────────────────────────

    public function testI15PostingIntoClosingPeriodRequiresFlag(): void
    {
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertSame('closing', (string) $this->periods->findById($this->supplierId, $this->periodId)['status']);

        // Bez flagu → period_not_open
        try {
            $this->posting->postDocument($this->supplierId, 'manual', null, [
                ['account_code' => '518', 'side' => 'debit', 'amount' => 100.00],
                ['account_code' => '321', 'side' => 'credit', 'amount' => 100.00],
            ], ['entry_date' => self::YEAR . '-12-31', 'posted_by' => $this->userId]);
            self::fail('Očekávána PostingException period_not_open.');
        } catch (PostingException $e) {
            self::assertSame('period_not_open', $e->errorCode);
        }

        // S flagem přes ClosingService (asistent dohadů, R22) → OK
        $result = $this->closing->createAssistedEntry($this->supplierId, $this->periodId, 'estimates', [
            'row_version' => $this->rv(),
            'rule_key' => 'estimate.liability',
            'amount' => 500.00,
            'description' => 'Dohad — nevyfakturovaná energie',
            'counter_account' => '518',
        ], $this->meta());

        $entry = $this->journal->find((int) $result['entry_id'], $this->supplierId);
        self::assertNotNull($entry, 'Asistovaný zápis do období ve stavu closing je zaúčtovaný (R7).');
        self::assertNotNull($entry['posted_at']);
        self::assertSame(self::ENDS_ON, (string) $entry['entry_date'], 'entry_date = ends_on (R22).');
        self::assertSame('ID-' . self::YEAR . '-0001', (string) $result['document_no'], 'Asistované zápisy dostávají číslo z řady manual vždy.');
    }

    public function testI15FlagDoesNotBypassApprovedPeriod(): void
    {
        $this->setStatusRaw('approved');

        try {
            $this->posting->postDocument($this->supplierId, 'manual', null, [
                ['account_code' => '518', 'side' => 'debit', 'amount' => 100.00],
                ['account_code' => '321', 'side' => 'credit', 'amount' => 100.00],
            ], [
                'entry_date' => self::YEAR . '-12-31',
                'posted_by' => $this->userId,
                'allow_closing_period' => true,
            ]);
            self::fail('Flag allow_closing_period NIKDY nepovoluje closed/approved (R7).');
        } catch (PostingException $e) {
            self::assertSame('period_not_open', $e->errorCode);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seedRevenue(): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1000.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 1000.00],
        ], ['entry_date' => self::YEAR . '-05-01', 'posted_by' => $this->userId]);
    }

    /** Celý workflow do stavu closed (kroky skip, FX bez položek). */
    private function runChainToClosed(): void
    {
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $this->closing->start($sid, $pid, $this->rv(), $this->meta());
        $this->closing->runPrecheck($sid, $pid, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'depreciation', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($sid, $pid, [], $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'estimates', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'deferrals', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'provisions', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'income_tax', 'skipped', null, $this->rv(), $this->meta());
        $this->completeInventory($sid, $pid, $this->userId);
        $this->closing->closeBooks($sid, $pid, $this->rv(), $this->meta());
    }

    /** EP-6: dokončí inventarizaci rozvahových účtů (skutečný = účetní → resolved), aby closeBooks neblokoval. */
    private function completeInventory(int $sid, int $pid, ?int $uid): void
    {
        $rv = (int) $this->periods->findById($sid, $pid)['row_version'];
        $items = [];
        foreach ($this->closing->inventoryPreview($sid, $pid)['rows'] as $r) {
            $items[(int) $r['account_id']] = ['counted_balance' => (float) $r['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $this->closing->saveInventory($sid, $pid, $rv, ['complete' => true], $items, ['user_id' => $uid]);
    }

    /** Přímé nastavení stavu (obchází automat — příprava výchozího stavu matice). */
    private function setStatusRaw(string $status): void
    {
        $this->db->pdo()->prepare('UPDATE accounting_periods SET status = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$status, $this->periodId, $this->supplierId]);
    }

    private function rv(): int
    {
        return (int) $this->periods->findById($this->supplierId, $this->periodId)['row_version'];
    }

    /** @return array{user_id:int, posted_by:int} */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function postStatus(array $body): array
    {
        return $this->call('status', ['id' => (string) $this->periodId], $body);
    }

    /**
     * @param array<string,string> $args
     * @param array<string,mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, array $args, array $body): array
    {
        $req = $this->request('POST', $body);
        $resp = $args === []
            ? $this->action->{$method}($req, new Psr7Response())
            : $this->action->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /**
     * @param array<string,mixed> $body
     */
    private function request(string $method, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/accounting/periods')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        return $req;
    }
}
