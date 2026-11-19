<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Ruční storno zápisu uzávěrky nebo otevření účtů založeného průvodcem se z deníku
 * odmítne. Storno nese týž zdroj bez source_id, takže by se tvářilo jako uzavření knih
 * a zmrazilo dimenze období. Tyto zápisy vrací průvodce uzávěrkou (vrácení kroku).
 * Převzaté otevření z migrace (bez source_id) stornovat jde.
 */
#[Group('integration')]
final class ClosingEntryManualReversalTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private JournalAction $journalAction;
    private PostingService $posting;
    private JournalEntryRepository $journal;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->journalAction = $container->get(JournalAction::class);
        $this->posting = $container->get(PostingService::class);
        $this->journal = $container->get(JournalEntryRepository::class);

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query("SELECT id FROM supplier WHERE accounting_mode='double_entry' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->periodId = $container->get(AccountingPeriodRepository::class)
            ->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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
     * Zápisy průvodce: uzávěrka (i bez source_id, např. převzatá z jiného programu)
     * a otevření účtů, které založil krok Otevření nového roku (source_id = období).
     *
     * @return array<string,array{string,bool}>
     */
    public static function wizardSources(): array
    {
        return [
            'uzávěrka průvodce' => ['closing', true],
            'uzávěrka bez source_id' => ['closing', false],
            'otevření průvodce' => ['opening', true],
        ];
    }

    #[DataProvider('wizardSources')]
    public function testJournalReverseRefusesWizardEntry(string $source, bool $withSourceId): void
    {
        $entryId = $this->entry($source, $withSourceId ? $this->periodId : null);
        $before = $this->reversals($source);

        $res = $this->reverse($entryId);

        self::assertSame(409, $res['status'], (string) json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('closing_reversal_forbidden', $res['body']['error']['code'] ?? $res['body']['code'] ?? null);
        self::assertStringContainsString('průvodci', (string) json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertNull($this->journal->find($entryId, $this->supplierId)['reversed_by'], 'Zápis zůstává nestornovaný.');
        self::assertSame($before, $this->reversals($source), 'Nevznikl protizápis bez source_id.');
    }

    #[DataProvider('wizardSources')]
    public function testManualReversalServiceRefusesWizardEntry(string $source, bool $withSourceId): void
    {
        $entryId = $this->entry($source, $withSourceId ? $this->periodId : null);

        $this->expectException(PostingException::class);
        $this->expectExceptionMessage('průvodci');
        $this->posting->reverseManual($this->supplierId, $entryId, ['user_id' => $this->userId]);
    }

    /**
     * Převzatý otevírací zápis z migrace nemá source_id. Chybně převzatý počáteční stav
     * jde opravit jen ručním stornem, proto zůstává povolené (storno otevření období
     * dimenze nezmrazí, closeBooksSql se týká jen uzávěrky).
     */
    public function testJournalReverseStillReversesImportedOpening(): void
    {
        $entryId = $this->entry('opening', null);

        $res = $this->reverse($entryId);

        self::assertSame(201, $res['status'], (string) json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertNotNull($this->journal->find($entryId, $this->supplierId)['reversed_by']);
    }

    public function testJournalReverseStillReversesManualEntry(): void
    {
        $entryId = $this->entry('manual', null);

        $res = $this->reverse($entryId);

        self::assertSame(201, $res['status'], (string) json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertNotNull($this->journal->find($entryId, $this->supplierId)['reversed_by']);
    }

    private function entry(string $source, ?int $sourceId): int
    {
        return $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id' => $this->periodId,
            'entry_date' => self::YEAR . '-12-31',
            'description' => 'Test ' . $source,
            'source_type' => $source,
            'source_id' => $sourceId,
            'posted_at' => self::YEAR . '-12-31 12:00:00',
        ], [
            ['account_id' => $this->accountId('311'), 'side' => 'debit', 'amount' => 100],
            ['account_id' => $this->accountId('395'), 'side' => 'credit', 'amount' => 100],
        ]);
    }

    private function accountId(string $code): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $stmt->execute([$this->supplierId, $code]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id, 'Chybí účet ' . $code);
        return $id;
    }

    private function reversals(string $source): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND period_id = ? AND source_type = ? AND source_id IS NULL'
        );
        $stmt->execute([$this->supplierId, $this->periodId, $source]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function reverse(int $entryId): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/accounting/journal/' . $entryId . '/reverse')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
        $resp = $this->journalAction->reverse($req, new Psr7Response(), ['id' => (string) $entryId]);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
